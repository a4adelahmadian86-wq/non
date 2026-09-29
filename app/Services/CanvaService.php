<?php
namespace App\Services;
use App\Models\CanvaConnection;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
class CanvaService {
 private const API='https://api.canva.com/rest/v1';
 public function configured():bool{return filled(SiteSetting::read('canva_client_id'))&&filled(SiteSetting::read('canva_client_secret'))&&filled(SiteSetting::read('canva_redirect_uri'));}
 public function authorizeUrl(User $user):array {
  if(!$this->configured())throw new RuntimeException('canva_not_configured');
  $verifier=Str::random(64);$state=Str::random(64);$challenge=rtrim(strtr(base64_encode(hash('sha256',$verifier,true)), '+/','-_'),'=');
  session(['canva_oauth'=>['state'=>$state,'verifier'=>$verifier]]);
  $scope=(string)SiteSetting::read('canva_scopes','design:meta:read design:content:read design:content:write asset:read asset:write');
  $query=http_build_query(['code_challenge'=>$challenge,'code_challenge_method'=>'s256','scope'=>$scope,'response_type'=>'code','client_id'=>SiteSetting::read('canva_client_id'),'state'=>$state,'redirect_uri'=>SiteSetting::read('canva_redirect_uri')]);
  return ['url'=>'https://www.canva.com/api/oauth/authorize?'.$query,'state'=>$state];
 }
 public function callback(User $user,string $code,string $state):CanvaConnection {
  $oauth=session()->pull('canva_oauth');if(!$oauth||!hash_equals($oauth['state']??'', $state))throw new RuntimeException('canva_oauth_state_invalid');
  $response=Http::asForm()->withBasicAuth((string)SiteSetting::read('canva_client_id'),(string)SiteSetting::read('canva_client_secret'))->post('https://api.canva.com/rest/v1/oauth/token',['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>SiteSetting::read('canva_redirect_uri'),'code_verifier'=>$oauth['verifier']]);
  if(!$response->successful())throw new RuntimeException('canva_token_exchange_failed');
  $j=$response->json();$conn=CanvaConnection::updateOrCreate(['user_id'=>$user->id],['access_token'=>$j['access_token']??null,'refresh_token'=>$j['refresh_token']??null,'expires_at'=>now()->addSeconds((int)($j['expires_in']??14400)),'scopes'=>preg_split('/\s+/',trim((string)($j['scope']??'')), -1,PREG_SPLIT_NO_EMPTY),'status'=>'connected','last_checked_at'=>now()]);
  return $conn;
 }
 public function token(CanvaConnection $connection):string {
  if($connection->expires_at && $connection->expires_at->gt(now()->addMinute()) && $connection->access_token)return $connection->access_token;
  if(!$connection->refresh_token)throw new RuntimeException('canva_reauthorization_required');
  $response=Http::asForm()->withBasicAuth((string)SiteSetting::read('canva_client_id'),(string)SiteSetting::read('canva_client_secret'))->post('https://api.canva.com/rest/v1/oauth/token',['grant_type'=>'refresh_token','refresh_token'=>$connection->refresh_token]);
  if(!$response->successful())throw new RuntimeException('canva_token_refresh_failed');
  $j=$response->json();$connection->access_token=$j['access_token']??null;if(!empty($j['refresh_token']))$connection->refresh_token=$j['refresh_token'];$connection->expires_at=now()->addSeconds((int)($j['expires_in']??14400));$connection->last_checked_at=now();$connection->status='connected';$connection->save();return (string)$connection->access_token;
 }
 public function request(CanvaConnection $c,string $method,string $path,array $options=[]):array {
  $token=$this->token($c);$req=Http::withToken($token)->acceptJson()->timeout(30);
  $response=$req->send($method,self::API.$path,$options);
  if($response->status()===401){$c->status='reauthorize';$c->save();throw new RuntimeException('canva_unauthorized');}
  if(!$response->successful())throw new RuntimeException('canva_api_http_'.$response->status());
  return $response->json();
 }
 public function designs(User $user,array $query=[]):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();$qs=http_build_query($query);return $this->request($c,'GET','/designs'.($qs?'?'.$qs:''));}
 public function design(User $user,string $id):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();return $this->request($c,'GET','/designs/'.rawurlencode($id));}
 public function createDoc(User $user,string $title):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();return $this->request($c,'POST','/designs',['headers'=>['Content-Type'=>'application/json'],'json'=>['type'=>'type_and_asset','design_type'=>['type'=>'preset','name'=>'doc'],'title'=>$title]]);}
 public function export(User $user,string $designId,string $type='pdf'):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();return $this->request($c,'POST','/exports',['headers'=>['Content-Type'=>'application/json'],'json'=>['design_id'=>$designId,'format'=>['type'=>$type]]]);}
 public function import(User $user,string $bytes,string $title,string $mime):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();$token=$this->token($c);$metadata=json_encode(['title_base64'=>base64_encode(mb_substr($title,0,50)),'mime_type'=>$mime],JSON_UNESCAPED_SLASHES);$response=Http::withToken($token)->withHeaders(['Content-Type'=>'application/octet-stream','Import-Metadata'=>$metadata])->withBody($bytes,'application/octet-stream')->timeout(120)->post(self::API.'/imports');if(!$response->successful())throw new RuntimeException('canva_import_http_'.$response->status());return $response->json();}
 public function importStatus(User $user,string $jobId):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();return $this->request($c,'GET','/imports/'.rawurlencode($jobId));}
 public function exportStatus(User $user,string $jobId):array{$c=CanvaConnection::where('user_id',$user->id)->firstOrFail();return $this->request($c,'GET','/exports/'.rawurlencode($jobId));}
}
