# Reader progress and bookmark synchronization

Authenticated readers keep their last section/page and up to 100 bookmarks in the private `store_reader_states` table, keyed by account and product. The reader continues to use browser-local storage as an offline/guest fallback and synchronizes authenticated changes to the server with a debounced same-origin request.

## API

- `GET /store/{product}/reader-state` returns the current account's state.
- `PUT /store/{product}/reader-state` stores `progress` and `bookmarks`.

The endpoints require authentication, return private no-store JSON, validate section/page bounds, and only accept section indexes within the preview set currently accessible to the account. Guests never receive an account-state endpoint in the reader markup.

## Data shape

```json
{
  "progress": { "section": 0, "page": 3 },
  "bookmarks": [
    { "section": 0, "page": 2 },
    { "section": 1, "page": 1 }
  ]
}
```

Bookmarks are deduplicated by section/page and capped at 100. The server's saved state is authoritative when it exists; otherwise an existing local state is uploaded on the next reader visit. Guest readers and signed-out sessions continue to use local storage only.
