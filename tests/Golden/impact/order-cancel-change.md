---
schema_version: 2
snapshot_id: "commerce-fixture"
target_id: "method:Fixtures\\CommerceApp\\Services\\OrderService::cancel"
generated_at: "2026-07-17T10:00:00+07:00"
---

# Change impact method:Fixtures\CommerceApp\Services\OrderService::cancel

## Summary

| Metric | Value |
| --- | ---: |
| changed symbol count | 1 |
| affected symbol count | 20 |
| affected module count | 5 |
| selected test count | 1 |
| uncertainty count | 9 |

## Changed symbols

- **modified** `method:Fixtures\CommerceApp\Services\OrderService::cancel`

## Affected symbols

- `cache:order-summary:{id}`: cache:order-summary:{id} is direct through external_contract from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `class:Fixtures\CommerceApp\Events\OrderCancelled`: class:Fixtures\CommerceApp\Events\OrderCancelled is direct through async from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `class:Fixtures\CommerceApp\Listeners\RestockInventory`: class:Fixtures\CommerceApp\Listeners\RestockInventory is transitive through async from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook`: class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook is transitive through async from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `class:Fixtures\CommerceApp\Notifications\OrderWasCancelled`: class:Fixtures\CommerceApp\Notifications\OrderWasCancelled is direct through external_contract from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `config:services.erp.base_url`: config:services.erp.base_url is transitive through external_contract from affected symbol method:Fixtures\CommerceApp\Listeners\SendCancellationWebhook::handle.
- `external:{config:services.erp.base_url}/orders/{id}/cancel`: external:{config:services.erp.base_url}/orders/{id}/cancel is transitive through external_contract from affected symbol method:Fixtures\CommerceApp\Listeners\SendCancellationWebhook::handle.
- `method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel`: method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel is direct through hard_dependency from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save`: method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save shares class:Fixtures\CommerceApp\Models\Order with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save shares table:orders with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity`: method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity shares class:Fixtures\CommerceApp\Models\InventoryStock with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity shares column:inventory_stocks.quantity with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity shares table:inventory_stocks with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent ambiguous_target uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_receiver uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_receiver uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent ambiguous_target uncertainty.; method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_receiver uncertainty.
- `method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount`: method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount shares column:orders.status with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\ShippingService::canShip`: method:Fixtures\CommerceApp\Services\ShippingService::canShip shares column:orders.status with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Dashboard`: module:Dashboard contains affected symbol method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount.
- `module:Integration`: module:Integration contains affected symbol class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook.
- `module:Inventory`: module:Inventory contains affected symbol class:Fixtures\CommerceApp\Listeners\RestockInventory.; module:Inventory contains affected symbol method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity.; module:Inventory contains affected symbol method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity.; module:Inventory contains affected symbol method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity.
- `module:Orders`: module:Orders contains affected symbol class:Fixtures\CommerceApp\Events\OrderCancelled.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save.; module:Orders contains affected symbol class:Fixtures\CommerceApp\Notifications\OrderWasCancelled.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.; module:Orders contains affected symbol method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save.
- `module:Shipping`: module:Shipping contains affected symbol method:Fixtures\CommerceApp\Services\ShippingService::canShip.
- `process:route:POST:orders/{order}/cancel`: process:route:POST:orders/{order}/cancel is direct through workflow from method:Fixtures\CommerceApp\Services\OrderService::cancel.; process:route:POST:orders/{order}/cancel is transitive through workflow from affected symbol method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel.; process:route:POST:orders/{order}/cancel is transitive through workflow from affected symbol route:POST:orders/{order}/cancel.
- `route:POST:orders/{order}/cancel`: route:POST:orders/{order}/cancel is transitive through hard_dependency from method:Fixtures\CommerceApp\Services\OrderService::cancel.

## Workflows

- `process:route:POST:orders/{order}/cancel`: process:route:POST:orders/{order}/cancel is direct through workflow from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `process:route:POST:orders/{order}/cancel`: process:route:POST:orders/{order}/cancel is transitive through workflow from affected symbol method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel.
- `process:route:POST:orders/{order}/cancel`: process:route:POST:orders/{order}/cancel is transitive through workflow from affected symbol route:POST:orders/{order}/cancel.

## Modules

- `module:Dashboard`: module:Dashboard contains affected symbol method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount.
- `module:Integration`: module:Integration contains affected symbol class:Fixtures\CommerceApp\Listeners\SendCancellationWebhook.
- `module:Inventory`: module:Inventory contains affected symbol class:Fixtures\CommerceApp\Listeners\RestockInventory.
- `module:Inventory`: module:Inventory contains affected symbol method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity.
- `module:Inventory`: module:Inventory contains affected symbol method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity.
- `module:Inventory`: module:Inventory contains affected symbol method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity.
- `module:Orders`: module:Orders contains affected symbol class:Fixtures\CommerceApp\Events\OrderCancelled.
- `module:Orders`: module:Orders contains affected symbol class:Fixtures\CommerceApp\Notifications\OrderWasCancelled.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Http\Controllers\OrderController::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Orders`: module:Orders contains affected symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `module:Shipping`: module:Shipping contains affected symbol method:Fixtures\CommerceApp\Services\ShippingService::canShip.

## Shared resources

- `method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save`: method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save shares class:Fixtures\CommerceApp\Models\Order with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save`: method:Fixtures\CommerceApp\Repositories\DatabaseOrderGateway::save shares table:orders with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity`: method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity shares class:Fixtures\CommerceApp\Models\InventoryStock with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity`: method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity shares column:inventory_stocks.quantity with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity`: method:Fixtures\CommerceApp\Services\InventoryReconciliationService::totalQuantity shares table:inventory_stocks with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount`: method:Fixtures\CommerceApp\Services\SalesDashboardService::cancelledOrderCount shares column:orders.status with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `method:Fixtures\CommerceApp\Services\ShippingService::canShip`: method:Fixtures\CommerceApp\Services\ShippingService::canShip shares column:orders.status with changed symbol method:Fixtures\CommerceApp\Services\OrderService::cancel.

## External contracts

- `cache:order-summary:{id}`: cache:order-summary:{id} is direct through external_contract from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `class:Fixtures\CommerceApp\Notifications\OrderWasCancelled`: class:Fixtures\CommerceApp\Notifications\OrderWasCancelled is direct through external_contract from method:Fixtures\CommerceApp\Services\OrderService::cancel.
- `config:services.erp.base_url`: config:services.erp.base_url is transitive through external_contract from affected symbol method:Fixtures\CommerceApp\Listeners\SendCancellationWebhook::handle.
- `external:{config:services.erp.base_url}/orders/{id}/cancel`: external:{config:services.erp.base_url}/orders/{id}/cancel is transitive through external_contract from affected symbol method:Fixtures\CommerceApp\Listeners\SendCancellationWebhook::handle.

## Selected tests

- `test:tests/Feature/CancelOrderTest.php::test_cancel_order_flow` (rank 2): Selected by direct static symbol reference to method:Fixtures\CommerceApp\Services\OrderService::cancel.

## Uncertainty

- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent ambiguous_target uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent ambiguous_target uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_receiver uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_receiver uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_receiver uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.
- `method:Fixtures\CommerceApp\Services\OrderService::cancel`: method:Fixtures\CommerceApp\Services\OrderService::cancel has adjacent unresolved_target uncertainty.

## Evidence

| ID | Origin | Detector | Source |
| --- | --- | --- | --- |
| `044063606022f98f8976e566500915fcc97e373439510f85e663b96a4353e6e5` | static_ast | module-resolver | [app/Listeners/RestockInventory.php:7](app/Listeners/RestockInventory.php#L7) |
| `07437e21c8d68bd680373bec2bfd369dbb8ae69496f5e02b72564fe4edf5f416` | static_ast | module-resolver | [app/Events/OrderCancelled.php:10](app/Events/OrderCancelled.php#L10) |
| `0998ce258b8e7d13dcf6d2a0ecab959c74028c350606506f3118bf63b7cb29bb` | static_ast | module-resolver | [app/Services/InventoryReconciliationService.php:9](app/Services/InventoryReconciliationService.php#L9) |
| `0c046bf6b5f067c38a023cef3a9d16f2309008e08af66a970a9ba6eafc1a4062` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:35](app/Services/OrderService.php#L35) |
| `1010fa0f8be9ca50e9a52c0d8483707e61bdae78a4e758b971cb816ef5ed4d62` | static_ast | eloquent_effect_detector | [app/Services/InventoryReconciliationService.php:11](app/Services/InventoryReconciliationService.php#L11) |
| `172d8f81001f8573fd7dc25351dfca574eeebc6666ecbaf25b5e062fbab3ab63` | static_ast | eloquent_effect_detector | [app/Services/OrderService.php:42](app/Services/OrderService.php#L42) |
| `1bba6d19ddbdde462dbcd5a10651864f5a7ffe1c52bb6bb4fee72ac5292032e6` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:41](app/Services/OrderService.php#L41) |
| `23fe55b66d3993cabcbc209c2e788374f2ff6f12a28f8fce1d44967d54fc4919` | static_ast | eloquent_effect_detector | [app/Repositories/DatabaseOrderGateway.php:12](app/Repositories/DatabaseOrderGateway.php#L12) |
| `34a0059b0b8b31b2b5ec0e114fa2bd560d8e824df7ce6c21dba1ec40b6438302` | static_ast | module-resolver | [app/Listeners/SendCancellationWebhook.php:9](app/Listeners/SendCancellationWebhook.php#L9) |
| `3e4d00131121619a905ea5d75a6d306909436117f206ac32ce583595faf99eab` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:35](app/Services/OrderService.php#L35) |
| `3f5d2b20126db8c7860bcf8ca0005b728219c423476d1ccbcdcd29782dacf353` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:36](app/Services/OrderService.php#L36) |
| `43a6f455268914cc7765e42a0548141ed1a68244fe7a35588165bcf4dbd5a4e2` | static_ast | notification_detector | [app/Services/OrderService.php:42](app/Services/OrderService.php#L42) |
| `44fc174061e8a11b8bbcaec0b103cee05757e26d8d06601d58e918ae3f9b64bb` | static_ast | config_effect_detector | [app/Listeners/SendCancellationWebhook.php:14](app/Listeners/SendCancellationWebhook.php#L14) |
| `46b6c9670773a9c936e4f08b3e895062037856254df86216f48b330a5c7fc8a9` | static_ast | cache_effect_detector | [app/Services/OrderService.php:41](app/Services/OrderService.php#L41) |
| `4f101777a9413959f402956d73da88a55fbdfd00285fb758acb8f31d1be5fb0b` | git_diff | git-diff-symbol-mapper | [app/Services/OrderService.php:22](app/Services/OrderService.php#L22) |
| `4f4547dad99f51c876eb22887ba812574d1e0b906dc2096199db24622e57775d` | static_ast | process-membership | — |
| `5a81bbc7560ff8346690c9492517c499a0fdabc58e1fafc1b059ac7cd8a1f326` | static_ast | module-resolver | [app/Http/Controllers/OrderController.php:19](app/Http/Controllers/OrderController.php#L19) |
| `5c8a86847c608c6c10bd6299665076ccb4a2e0ff22da5308a9f4ac04afb2584d` | static_ast | eloquent_effect_detector | [app/Services/OrderService.php:35](app/Services/OrderService.php#L35) |
| `6680758ed868d29bb90bdb10c136d2dfa86a96ee586fe542e8db21e03da2a5c7` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:42](app/Services/OrderService.php#L42) |
| `66939c583cbcde68a3eac72274bf2ff53afe69ea4235e5a8e855112439e45d25` | static_ast | eloquent_effect_detector | [app/Services/OrderService.php:35](app/Services/OrderService.php#L35) |
| `77cb55bb00b33de615d2162177656dc2940b881c82567c94e5822756dd64af8c` | static_ast | call-target-resolver | [app/Http/Controllers/OrderController.php:25](app/Http/Controllers/OrderController.php#L25) |
| `786ad2f1737a224e5f30256a9588e2aee139ec0dd9fe7a177fd7313c8fa1dcc3` | static_ast | module-resolver | [app/Repositories/DatabaseOrderGateway.php:10](app/Repositories/DatabaseOrderGateway.php#L10) |
| `7eaf5b3bbc0d1895463c4b22747913319b799149659eb6a16ecbfb78eac3b12c` | static_ast | route_detector | [routes/web.php:11](routes/web.php#L11) |
| `7efd37dc5d1cb736b153017ba9fe8d66328b2b312de948f3ed0badcf2d5b6283` | static_ast | eloquent_effect_detector | [app/Services/OrderService.php:31](app/Services/OrderService.php#L31) |
| `82182262efb152ddd2299c654a1e0b8251efcc6f965183d1aaaa0a6f9a1f7581` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:40](app/Services/OrderService.php#L40) |
| `830717aa0d6f8bd2a525e10ad09d6c2b3e6f35859b9aa5ff71358d78106c1d71` | static_ast | eloquent_effect_detector | [app/Services/InventoryReconciliationService.php:11](app/Services/InventoryReconciliationService.php#L11) |
| `849e2c1cfae84c96d543fa56577cf3ba41733d500ab74a6f1b724993cb933356` | static_ast | eloquent_effect_detector | [app/Services/InventoryReconciliationService.php:11](app/Services/InventoryReconciliationService.php#L11) |
| `90385b0416766d1bf9ceb47a5a1f513fefa74a5da8d69440d1d673bd71843c7d` | static_ast | module-resolver | [app/Notifications/OrderWasCancelled.php:10](app/Notifications/OrderWasCancelled.php#L10) |
| `93f2913a423e563f54a4d7082bebf3c296db08e8060397e116d9673f8cc921af` | static_ast | process-membership | — |
| `9817d106feb82357faa325c69b08c170e12935ed03b951989a96fc15a5f1e4ce` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:35](app/Services/OrderService.php#L35) |
| `ac0038fbf9e94586905fff9acc2b3abe74a14cd548e357716693d855f7c76e03` | static_ast | eloquent_effect_detector | [app/Services/OrderService.php:35](app/Services/OrderService.php#L35) |
| `b1cd3c959a872fe788bab1422c5aa8cbca848a3c2a82f8ac1f2e2c002b9ebcb8` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:30](app/Services/OrderService.php#L30) |
| `b1e672584a32593f5d81a2adc23d12c95123581418f2c34932a9b58f9def2e95` | static_ast | module-resolver | [app/Services/OrderService.php:20](app/Services/OrderService.php#L20) |
| `b659a221b824aeec45eb32ce38277d76d87b3b5ce6cfde564263e969097b46ba` | static_ast | http_client_effect_detector | [app/Listeners/SendCancellationWebhook.php:13](app/Listeners/SendCancellationWebhook.php#L13) |
| `bde3f64310f878f5a8e4e7344fb1ba85c8c0a6eac437476757ac035bbb936487` | static_ast | eloquent_effect_detector | [app/Repositories/DatabaseOrderGateway.php:12](app/Repositories/DatabaseOrderGateway.php#L12) |
| `bf0684a1e49cf024d237f9ac0bbd55960c7c2a0564ae653254d7baeac44737c6` | static_ast | eloquent_effect_detector | [app/Services/OrderService.php:42](app/Services/OrderService.php#L42) |
| `c51fea9592841478707cd756e97c13b537925871d520d3947d65fc048887e014` | static_ast | module-resolver | [app/Services/SalesDashboardService.php:9](app/Services/SalesDashboardService.php#L9) |
| `c616bdacbae082286451b549c15d5cbc553917f86bfdb35080f46474ae924c51` | laravel_boot | listener_detector | — |
| `c9e4052b59be0116288ce523fc59855efc9f1dab0f3b674a30015f4ee896dfe1` | static_ast | eloquent_effect_detector | [app/Services/ShippingService.php:11](app/Services/ShippingService.php#L11) |
| `d24ef15e9cb143ce4e170cd8265fb2cab2f4999f00fbfd17921d43c0ec63f6f3` | static_ast | event_dispatch_detector | [app/Services/OrderService.php:40](app/Services/OrderService.php#L40) |
| `d264ca68798aaa7501bb627a0aaf0ce7a2fd1c702dd003107309ca12e42d81f8` | static_ast | eloquent_effect_detector | [app/Services/SalesDashboardService.php:11](app/Services/SalesDashboardService.php#L11) |
| `df2500ad161f30e2a0a1fc844df1891ac1bb976175e19f1f1de43650538e299c` | static_ast | operational-handler-resolution | [app/Listeners/SendCancellationWebhook.php:11](app/Listeners/SendCancellationWebhook.php#L11) |
| `dfc998cd8a358f3da8be05264766d35d89f7afb5b0685c01d5498da674a6cca2` | static_ast | process-membership | — |
| `ecb15878308aa5c1fa3bf809b0d63c30b5cbfbd7dd0fe5cfb6e1d38867f3a6d8` | static_ast | module-resolver | [app/Services/ShippingService.php:9](app/Services/ShippingService.php#L9) |
| `f079ec43c99d16ce6aeb622a0b595dc488c19f691d588f7a8be47595aafe1b5b` | laravel_boot | listener_detector | — |
| `f43afe88a7dda077583e4b202f00869e33611b99b55eab33969dbc5c21ebddef` | static_ast | test-reference-detector | [tests/Feature/CancelOrderTest.php:26](tests/Feature/CancelOrderTest.php#L26) |
| `fab8fbb6879499e04ee77bbbcf9fa46de05b9ec81381ffb0af517bd34f702238` | static_ast | impact-diagnostic-adjacency | [app/Services/OrderService.php:41](app/Services/OrderService.php#L41) |
