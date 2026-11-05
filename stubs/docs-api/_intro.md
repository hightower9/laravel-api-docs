## Getting started

Every endpoint in this reference is generated from the Laravel routes, controllers and FormRequest rules, so it always matches the code you are running.

Use the **server menu** in the top bar to send requests to another environment or any other URL. Tokens are kept separately for each server.

All requests and responses are JSON. Send `Accept: application/json` so validation and auth errors come back as JSON instead of redirects.

## Authentication

1. Open the login endpoint, `POST {{login}}`, in the sidebar.
2. Enter your credentials in the **Try it** panel and press **Send** (or Ctrl + Enter).
3. The access token in the response is saved automatically, and every request you send from this page includes `Authorization: Bearer <token>`.

Calling `POST {{logout}}`, or **Sign out** in the top-right menu, forgets the token. You can also paste an existing token there.

From your own client, send the token on every request:

```http
Authorization: Bearer <token>
Accept: application/json
```
