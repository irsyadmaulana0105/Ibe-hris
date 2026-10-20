# IBE ERP REST JSON and Webhook Contract

## Stable API boundary

- Base path: `/api/v1`
- Media type: `application/json`
- Authentication: Laravel Sanctum bearer token
- Tenant boundary: authenticated `company_id`; clients may never select another tenant implicitly
- Successful responses use `data`, optional `message`, and paginated `meta`
- Validation errors use HTTP 422; authentication 401; authorization 403; conflict 409; rate limit 429
- Every API response includes `X-Correlation-Id`. Clients should send the same header through a distributed workflow.

## Idempotent mutations

Send a unique `Idempotency-Key` on financial and workflow mutations. The same key and identical body replays the original response. Reusing it with a different body or route returns HTTP 409. Keys live for 24 hours.

```http
POST /api/v1/admin/invoices/42/pay
Authorization: Bearer ...
Content-Type: application/json
Idempotency-Key: payment-42-20260802-001
X-Correlation-Id: checkout-01K...
```

## Webhook management

Superadmin endpoints:

- `GET /api/v1/admin/webhooks`
- `POST /api/v1/admin/webhooks`
- `PATCH /api/v1/admin/webhooks/{endpoint}`
- `DELETE /api/v1/admin/webhooks/{endpoint}` (deactivates and preserves audit history)
- `POST /api/v1/admin/webhooks/{endpoint}/test`
- `GET /api/v1/admin/webhooks/deliveries/log`
- `POST /api/v1/admin/webhooks/deliveries/{delivery}/retry`

The signing secret is returned once when the endpoint is created and encrypted at rest. Production endpoints must use HTTPS.

## Delivery signature

Webhook requests contain `X-IBE-Event`, `X-IBE-Event-Id`, `X-IBE-Timestamp`, and `X-IBE-Signature`. Verification input is:

```text
HMAC_SHA256(secret, timestamp + "." + raw_request_body)
```

Reject timestamps older than five minutes and store `event_id` to prevent replay. Return any HTTP 2xx response only after durably accepting the event. Delivery retries use 30 seconds, 2 minutes, 10 minutes, and 30 minutes backoff, for at most five attempts.

## Initial event catalog

Employee lifecycle, sales order creation/payment completion, purchase request lifecycle, purchase order, goods receipt, invoices, stock, shipment, and project progress are versioned event names. Consumers must ignore unknown JSON properties for forward compatibility.

## Operations

Production requires a persistent queue worker and scheduler. Before promotion run migrations, cache configuration/routes, start or reload queue workers, test one signed delivery, and verify the delivery log. Rollback deactivates endpoints first; event and delivery records remain available for audit.
