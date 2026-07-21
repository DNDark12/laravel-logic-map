# Laravel Logic Map V2 API

The V2 API is served under `/logic-map/api` when HTTP access is enabled for the current environment. Every response uses the same envelope:

```json
{"ok":true,"data":{},"message":null,"errors":null,"meta":{}}
```

Node IDs used in URL path segments are base64url encoded. Search and response payloads return canonical IDs and `encoded_id` values for navigation.

## Status

`GET /logic-map/api/status`

```json
{"ok":true,"data":{"active":true,"snapshot_id":"snapshot-example","node_count":124,"edge_count":311},"message":null,"errors":null,"meta":{}}
```

## Search

`GET /logic-map/api/symbols/search?q=order`

```json
{"ok":true,"data":{"selection":null,"results":[{"id":"class:App\\Http\\Controllers\\OrderController","encoded_id":"Y2xhc3M6QXBwXEh0dHBcQ29udHJvbGxlcnNcT3JkZXJDb250cm9sbGVy","kind":"controller","name":"OrderController","qualified_name":"App\\Http\\Controllers\\OrderController"}]},"message":null,"errors":null,"meta":{"truncated":false}}
```

## Symbol context

`GET /logic-map/api/symbols/{encoded-id}/context`

```json
{"ok":true,"data":{"symbol":{"id":"method:App\\Services\\OrderService::cancel","encoded_id":"bWV0aG9kOkFwcFxTZXJ2aWNlc1xPcmRlclNlcnZpY2U6OmNhbmNlbA","kind":"method"},"incoming":[],"outgoing":[],"processes":[],"modules":[],"effects":[],"evidence":[],"runtime":{"coverage":"No runtime data available","relations":[]}},"message":null,"errors":null,"meta":{"truncated":false}}
```

## Workflow

`GET /logic-map/api/workflows/{encoded-id}` projects one route, command, job, or concrete entrypoint. A single workflow also accepts `?format=markdown` and `?format=mermaid`.

```json
{"ok":true,"data":{"identity":{"schema_version":2,"workflow_id":"workflow:route:POST:orders/{order}/cancel","encoded_workflow_id":"d29ya2Zsb3c6cm91dGU6UE9TVDpvcmRlcnMve29yZGVyfS9jYW5jZWw"},"entrypoint":{"node_id":"route:POST:orders/{order}/cancel","encoded_id":"cm91dGU6UE9TVDpvcmRlcnMve29yZGVyfS9jYW5jZWw"},"steps":[],"transitions":[],"transactions":[],"effects":[],"gaps":[],"runtime":{"coverage":"No runtime data available","relations":[]}},"message":null,"errors":null,"meta":{"truncated":false}}
```

## Module workflow

`GET /logic-map/api/workflows/{encoded-module-id}` returns the module's entry-workflow collection, not a synthetic one-node workflow. Module and class/container collections support JSON over HTTP.

```json
{"ok":true,"data":{"identity":{"schema_version":2,"workflow_id":"module-workflow:module:Orders","workflow_type":"module"},"module":{"node_id":"module:Orders","encoded_id":"bW9kdWxlOk9yZGVycw","name":"Orders"},"summary":{"entrypoint_count":3,"step_count":18},"entry_workflows":[],"runtime":{"coverage":"No runtime data available","relations":[]}},"message":null,"errors":null,"meta":{"truncated":false}}
```

## Modules

`GET /logic-map/api/modules` lists module summaries. `GET /logic-map/api/modules/{encoded-id}` adds membership, relations, shared resources, and entrypoints.

```json
{"ok":true,"data":{"module":{"id":"module:Orders","encoded_id":"bW9kdWxlOk9yZGVycw","name":"Orders"},"members":[],"inbound":[],"outbound":[],"shared_resources":[],"entrypoints":[]},"message":null,"errors":null,"meta":{"truncated":false}}
```

## Impact

`POST /logic-map/api/impact` accepts either a canonical `symbol` or a Git `base`/`head` pair. `runtime_sessions` optionally overlays selected valid observations.

```json
{"ok":true,"data":{"change_set":{"count":1},"changed_symbols":[{"new_node_id":"method:App\\Services\\OrderService::cancel"}],"affected_symbols":[],"selection":null,"evidence":[],"runtime":{"coverage":"No runtime data available"}},"message":null,"errors":null,"meta":{"truncated":false}}
```

## Errors

Clients must inspect `ok`, `errors.code`, and `meta`; an empty collection does not imply a missing index or an unbounded result.

```json
{"ok":false,"data":null,"message":"A non-empty search query is required.","errors":{"code":"validation_failed","fields":{"q":["The q field is required."]}},"meta":{}}
```

Common error codes are `index_missing`, `validation_failed`, `invalid_encoded_id`, `symbol_not_found`, `workflow_not_found`, `impact_invalid`, `invalid_git_ref`, and `environment_not_allowed`.
