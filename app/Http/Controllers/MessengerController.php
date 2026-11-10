<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessengerController extends Controller
{
    public function index(Request $request)
    {
        $userId = (int) $request->user()->id;
        $conversations = Conversation::query()
            ->visibleTo($userId)
            ->with('participants:id,name')
            ->withCount(['messages as unread_messages_count' => function ($q) use ($userId) {
                $q->where('user_id', '!=', $userId)
                    ->whereRaw('conversation_messages.id > COALESCE((SELECT last_read_message_id FROM conversation_participants WHERE conversation_participants.conversation_id = conversation_messages.conversation_id AND conversation_participants.user_id = ? LIMIT 1), 0)', [$userId]);
            }])
            ->orderByDesc('last_message_at')->orderByDesc('id')->paginate(30);

        $users = User::query()->where('id', '!=', $userId)->where('is_blocked', false)->orderBy('name')->limit(200)->get(['id', 'name', 'email']);

        return view('messenger.index', compact('conversations', 'users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:direct,group'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:49'],
            'participant_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'title' => ['nullable', 'string', 'max:160'],
        ]);

        $ids = collect($data['participant_ids'])->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $request->user()->id)->unique()->sort()->values();
        abort_if($ids->isEmpty(), 422, 'حداقل یک مخاطب دیگر انتخاب کنید.');
        abort_unless(User::query()->whereIn('id', $ids)->where('is_blocked', false)->count() === $ids->count(), 422, 'یکی از مخاطبان انتخاب‌شده در دسترس نیست.');
        abort_if($data['type'] === 'group' && blank($data['title']), 422, 'عنوان گروه الزامی است.');

        $allIds = $ids->push((int) $request->user()->id)->unique()->sort()->values();
        $directKey = $data['type'] === 'direct' ? hash('sha256', $allIds->implode(':')) : null;

        $conversation = DB::transaction(function () use ($request, $data, $allIds, $directKey) {
            if ($directKey) {
                $existing = Conversation::query()->where('direct_key', $directKey)->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }
            }

            $conversation = Conversation::create([
                'type' => $data['type'],
                'title' => $data['type'] === 'group' ? trim($data['title']) : null,
                'created_by' => $request->user()->id,
                'direct_key' => $directKey,
            ]);

            $now = now();
            $pivot = $allIds->mapWithKeys(fn ($id) => [$id => [
                'role' => $id === (int) $request->user()->id ? 'owner' : 'member',
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]])->all();
            $conversation->participants()->attach($pivot);

            return $conversation;
        });

        return redirect()->route('messenger.show', $conversation);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);
        $messages = $conversation->messages()->with(['user:id,name', 'replyTo.user:id,name'])
            ->latest('id')->paginate(50)->withQueryString();
        $messages->setCollection($messages->getCollection()->reverse()->values());

        $conversation->load('participants:id,name');
        $this->markRead($request, $conversation, (int) ($conversation->messages()->max('id') ?? 0));

        return view('messenger.show', compact('conversation', 'messages'));
    }

    public function send(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);
        abort_if($conversation->is_locked, 423, 'این گفتگو موقتاً قفل شده است.');

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:10000'],
            'reply_to_id' => ['nullable', 'integer'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,txt,doc,docx,xls,xlsx,zip'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $files = $request->file('attachments', []);
        abort_if($body === '' && count($files) === 0, 422, 'متن یا فایل پیام را وارد کنید.');

        $replyTo = null;
        if (! empty($data['reply_to_id'])) {
            $replyTo = $conversation->messages()->whereKey($data['reply_to_id'])->firstOrFail();
        }

        $attachments = [];
        foreach ($files as $file) {
            $path = $file->store('messenger/'.$conversation->id, 'private');
            try {
                $inspection = app(\App\Services\UploadSecurityService::class)->inspect(
                    $path,
                    (string) $file->getMimeType(),
                    10 * 1024 * 1024,
                );
                if (empty($inspection['safe'])) {
                    throw new \RuntimeException('فایل از بررسی امنیتی داخلی عبور نکرد.');
                }
            } catch (\Throwable $exception) {
                Storage::disk('private')->delete($path);
                foreach ($attachments as $stored) {
                    Storage::disk('private')->delete($stored['path']);
                }
                throw ValidationException::withMessages([
                    'attachments' => ['فایل پیوست توسط بررسی امنیتی داخلی پذیرفته نشد: '.$exception->getMessage()],
                ]);
            }

            $attachments[] = [
                'path' => $path,
                'disk' => 'private',
                'name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
                'size' => (int) $file->getSize(),
                'mime' => (string) ($inspection['mime'] ?? $file->getMimeType()),
                'sha256' => (string) ($inspection['checksum'] ?? ''),
                'scan' => (string) ($inspection['scan'] ?? 'farast_static_v1'),
                'risk_level' => (string) ($inspection['risk_level'] ?? 'unknown'),
            ];
        }

        $message = DB::transaction(function () use ($conversation, $request, $body, $replyTo, $attachments) {
            $message = $conversation->messages()->create([
                'user_id' => $request->user()->id,
                'reply_to_id' => $replyTo?->id,
                'body' => $body,
                'attachments' => $attachments ?: null,
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                'last_message_preview' => Str::limit($body !== '' ? $body : 'فایل پیوست', 220),
            ])->save();

            return $message;
        });

        foreach ($conversation->participants()->where('users.id', '!=', $request->user()->id)->get() as $recipient) {
            app(\App\Services\NotificationService::class)->push(
                (int) $recipient->id,
                'message_received',
                'پیام جدید در فراست',
                Str::limit($body !== '' ? $body : 'یک فایل برای شما ارسال شد', 180),
                ['url' => route('messenger.show', $conversation), 'conversation_id' => $conversation->id, 'message_id' => $message->id],
                false,
                'conversation:'.$conversation->id.':message:'.$message->id.':user:'.$recipient->id,
            );
        }

        return response()->json([
            'ok' => true,
            'message' => [
                'id' => $message->id,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
                'user_id' => $message->user_id,
                'user_name' => $request->user()->name,
                'attachments' => collect($message->attachments ?? [])->map(fn ($a, $i) => [
                    'name' => $a['name'] ?? 'پیوست',
                    'size' => $a['size'] ?? 0,
                    'url' => route('messenger.attachment', [$conversation, $message, $i]),
                ])->values(),
            ],
        ], 201);
    }

    public function poll(Request $request, Conversation $conversation)
    {
        $this->authorizeParticipant($request, $conversation);
        $after = max(0, (int) $request->query('after', 0));
        $messages = $conversation->messages()->where('id', '>', $after)
            ->with('user:id,name')->orderBy('id')->limit(100)->get();

        if ($messages->isNotEmpty()) {
            $this->markRead($request, $conversation, (int) $messages->max('id'));
        }

        return response()->json(['messages' => $messages->map(fn ($m) => [
            'id' => $m->id,
            'body' => $m->body,
            'user_id' => $m->user_id,
            'user_name' => $m->user?->name ?? 'کاربر حذف‌شده',
            'created_at' => $m->created_at?->toIso8601String(),
            'attachments' => collect($m->attachments ?? [])->map(fn ($a, $i) => [
                'name' => $a['name'] ?? 'پیوست',
                'url' => route('messenger.attachment', [$conversation, $m, $i]),
            ])->values(),
        ])]);
    }

    public function attachment(Request $request, Conversation $conversation, ConversationMessage $message, int $index)
    {
        $this->authorizeParticipant($request, $conversation);
        abort_unless((int) $message->conversation_id === (int) $conversation->id, 404);
        $attachment = $message->attachments[$index] ?? null;
        abort_unless(is_array($attachment) && ($attachment['disk'] ?? null) === 'private' && filled($attachment['path'] ?? null), 404);

        $disk = Storage::disk('private');
        abort_unless($disk->exists($attachment['path']), 404);

        return $disk->download($attachment['path'], $attachment['name'] ?? 'attachment', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function markRead(Request $request, Conversation $conversation, ?int $messageId = null)
    {
        $this->authorizeParticipant($request, $conversation);
        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)
            ->update(['last_read_message_id' => $messageId ?: (int) ($conversation->messages()->max('id') ?? 0), 'read_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function authorizeParticipant(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->participants()->where('users.id', $request->user()->id)->exists(), 404);
    }
}
