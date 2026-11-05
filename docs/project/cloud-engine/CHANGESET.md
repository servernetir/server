# File-by-file change summary

Feature changes relative to the initial implementation base. No production configuration or credentials are included.

| File | Responsibility |
|---|---|
| `docs/project/CLOUD-ENGINE-API.md` | Architecture, operations, evidence or delivery documentation: CLOUD ENGINE API. |
| `docs/project/CLOUD-ENGINE-ARCHITECTURE.md` | Architecture, operations, evidence or delivery documentation: CLOUD ENGINE ARCHITECTURE. |
| `docs/project/README.md` | Architecture, operations, evidence or delivery documentation: README. |
| `docs/project/cloud-engine/ACCEPTANCE.md` | Architecture, operations, evidence or delivery documentation: ACCEPTANCE. |
| `docs/project/cloud-engine/BASELINE-COMPARISON.json` | Architecture, operations, evidence or delivery documentation: BASELINE COMPARISON. |
| `docs/project/cloud-engine/CHANGESET.md` | Architecture, operations, evidence or delivery documentation: CHANGESET. |
| `docs/project/cloud-engine/HARDWARE-AND-ECONOMICS.md` | Architecture, operations, evidence or delivery documentation: HARDWARE AND ECONOMICS. |
| `docs/project/cloud-engine/NODE-RUNBOOK.md` | Architecture, operations, evidence or delivery documentation: NODE RUNBOOK. |
| `docs/project/cloud-engine/README.md` | Architecture, operations, evidence or delivery documentation: README. |
| `docs/project/cloud-engine/SECURITY-REVIEW.md` | Architecture, operations, evidence or delivery documentation: SECURITY REVIEW. |
| `docs/project/cloud-engine/VALIDATION.md` | Architecture, operations, evidence or delivery documentation: VALIDATION. |
| `infra/cloud-engine/.gitignore` | Isolated deployment/image/console/traffic/commercial tooling: .gitignore. |
| `infra/cloud-engine/TELEMETRY-CONSOLE.md` | Isolated deployment/image/console/traffic/commercial tooling: TELEMETRY CONSOLE. |
| `infra/cloud-engine/calculators/README.md` | Isolated deployment/image/console/traffic/commercial tooling: README. |
| `infra/cloud-engine/calculators/model.py` | Isolated deployment/image/console/traffic/commercial tooling: model. |
| `infra/cloud-engine/calculators/synthetic.example.json` | Isolated deployment/image/console/traffic/commercial tooling: synthetic.example. |
| `infra/cloud-engine/calculators/test_model.py` | Isolated deployment/image/console/traffic/commercial tooling: test model. |
| `infra/cloud-engine/console.env.example` | Isolated deployment/image/console/traffic/commercial tooling: console.env. |
| `infra/cloud-engine/console_gateway.py` | Isolated deployment/image/console/traffic/commercial tooling: console gateway. |
| `infra/cloud-engine/images/README.md` | Isolated deployment/image/console/traffic/commercial tooling: README. |
| `infra/cloud-engine/images/catalog.json` | Isolated deployment/image/console/traffic/commercial tooling: catalog. |
| `infra/cloud-engine/images/test_verify_import.py` | Isolated deployment/image/console/traffic/commercial tooling: test verify import. |
| `infra/cloud-engine/images/test_windows_marker_contract.py` | Isolated deployment/image/console/traffic/commercial tooling: test windows marker contract. |
| `infra/cloud-engine/images/verify_import.py` | Isolated deployment/image/console/traffic/commercial tooling: verify import. |
| `infra/cloud-engine/images/windows-firstboot-ready.ps1` | Isolated deployment/image/console/traffic/commercial tooling: windows firstboot ready. |
| `infra/cloud-engine/images/windows_marker_contract.py` | Isolated deployment/image/console/traffic/commercial tooling: windows marker contract. |
| `infra/cloud-engine/onboarding/README.md` | Isolated deployment/image/console/traffic/commercial tooling: README. |
| `infra/cloud-engine/onboarding/gate.py` | Isolated deployment/image/console/traffic/commercial tooling: gate. |
| `infra/cloud-engine/onboarding/install-pve.sh` | Isolated deployment/image/console/traffic/commercial tooling: install pve. |
| `infra/cloud-engine/onboarding/node.example.json` | Isolated deployment/image/console/traffic/commercial tooling: node.example. |
| `infra/cloud-engine/onboarding/preflight.py` | Isolated deployment/image/console/traffic/commercial tooling: preflight. |
| `infra/cloud-engine/onboarding/readiness.example.json` | Isolated deployment/image/console/traffic/commercial tooling: readiness.example. |
| `infra/cloud-engine/onboarding/render.py` | Isolated deployment/image/console/traffic/commercial tooling: render. |
| `infra/cloud-engine/onboarding/test_gate.py` | Isolated deployment/image/console/traffic/commercial tooling: test gate. |
| `infra/cloud-engine/onboarding/test_render.py` | Isolated deployment/image/console/traffic/commercial tooling: test render. |
| `infra/cloud-engine/requirements.txt` | Isolated deployment/image/console/traffic/commercial tooling: requirements. |
| `infra/cloud-engine/servernet-engine-console.service` | Isolated deployment/image/console/traffic/commercial tooling: servernet engine console. |
| `infra/cloud-engine/servernet-engine-traffic.service` | Isolated deployment/image/console/traffic/commercial tooling: servernet engine traffic. |
| `infra/cloud-engine/test_telemetry.py` | Isolated deployment/image/console/traffic/commercial tooling: test telemetry. |
| `infra/cloud-engine/traffic-collector.example.json` | Isolated deployment/image/console/traffic/commercial tooling: traffic collector.example. |
| `infra/cloud-engine/traffic_collector.py` | Isolated deployment/image/console/traffic/commercial tooling: traffic collector. |
| `website/app/Console/Commands/CloudEngineHealth.php` | Existing application integration seam: Cloud Engine Health. |
| `website/app/Console/Commands/CloudEngineWork.php` | Existing application integration seam: Cloud Engine Work. |
| `website/app/Http/Controllers/Account/CloudServerController.php` | Authorized account workflow and explicit responses. |
| `website/app/Http/Controllers/Account/EngineAccountController.php` | Authorized account workflow and explicit responses. |
| `website/app/Http/Controllers/Admin/CloudEngineController.php` | Authorized admin workflow and explicit responses. |
| `website/app/Http/Controllers/Api/CloudEngineController.php` | Authorized api workflow and explicit responses. |
| `website/app/Http/Controllers/Engine/EngineInternalController.php` | Authorized engine workflow and explicit responses. |
| `website/app/Http/Middleware/CloudEnginePrivacy.php` | Existing application integration seam: Cloud Engine Privacy. |
| `website/app/Http/Middleware/ConsoleHost.php` | Existing application integration seam: Console Host. |
| `website/app/Models/CloudPlan.php` | Existing application integration seam: Cloud Plan. |
| `website/app/Models/CustomerApiToken.php` | Existing application integration seam: Customer Api Token. |
| `website/app/Models/EngineAddress.php` | Infrastructure persistence, relationships and protected attributes: Engine Address. |
| `website/app/Models/EngineAgentCredential.php` | Infrastructure persistence, relationships and protected attributes: Engine Agent Credential. |
| `website/app/Models/EngineApiRequest.php` | Infrastructure persistence, relationships and protected attributes: Engine Api Request. |
| `website/app/Models/EngineConsoleGrant.php` | Infrastructure persistence, relationships and protected attributes: Engine Console Grant. |
| `website/app/Models/EngineImage.php` | Infrastructure persistence, relationships and protected attributes: Engine Image. |
| `website/app/Models/EngineIpEvent.php` | Infrastructure persistence, relationships and protected attributes: Engine Ip Event. |
| `website/app/Models/EngineNode.php` | Infrastructure persistence, relationships and protected attributes: Engine Node. |
| `website/app/Models/EngineOperation.php` | Infrastructure persistence, relationships and protected attributes: Engine Operation. |
| `website/app/Models/EnginePlanNode.php` | Infrastructure persistence, relationships and protected attributes: Engine Plan Node. |
| `website/app/Models/EngineSubnet.php` | Infrastructure persistence, relationships and protected attributes: Engine Subnet. |
| `website/app/Models/EngineVm.php` | Infrastructure persistence, relationships and protected attributes: Engine Vm. |
| `website/app/Services/Cloud/CloudManager.php` | Existing application integration seam: Cloud Manager. |
| `website/app/Services/Cloud/CloudProvisioner.php` | Existing application integration seam: Cloud Provisioner. |
| `website/app/Services/Cloud/Engine/EngineAccountPresenter.php` | Dedicated infrastructure service boundary: Engine Account Presenter. |
| `website/app/Services/Cloud/Engine/EngineApiError.php` | Dedicated infrastructure service boundary: Engine Api Error. |
| `website/app/Services/Cloud/Engine/EngineApiService.php` | Dedicated infrastructure service boundary: Engine Api Service. |
| `website/app/Services/Cloud/Engine/EngineConsoleService.php` | Dedicated infrastructure service boundary: Engine Console Service. |
| `website/app/Services/Cloud/Engine/EngineOperations.php` | Dedicated infrastructure service boundary: Engine Operations. |
| `website/app/Services/Cloud/Engine/EnginePaidGate.php` | Dedicated infrastructure service boundary: Engine Paid Gate. |
| `website/app/Services/Cloud/Engine/EngineProvider.php` | Dedicated infrastructure service boundary: Engine Provider. |
| `website/app/Services/Cloud/Engine/EngineTrafficService.php` | Dedicated infrastructure service boundary: Engine Traffic Service. |
| `website/app/Services/Cloud/Engine/EngineWorker.php` | Dedicated infrastructure service boundary: Engine Worker. |
| `website/app/Services/Cloud/Engine/Ipam.php` | Dedicated infrastructure service boundary: Ipam. |
| `website/app/Services/Cloud/Engine/NodeHealth.php` | Dedicated infrastructure service boundary: Node Health. |
| `website/app/Services/Cloud/Engine/Placement.php` | Dedicated infrastructure service boundary: Placement. |
| `website/app/Services/Cloud/Engine/ProxmoxTransport.php` | Dedicated infrastructure service boundary: Proxmox Transport. |
| `website/bootstrap/app.php` | Existing application integration seam: app. |
| `website/config/cloud-engine.php` | Existing application integration seam: cloud engine. |
| `website/database/migrations/2026_11_03_100100_create_cloud_engine_inventory.php` | Additive infrastructure schema/index: 2026 11 03 100100 create cloud engine inventory. |
| `website/database/migrations/2026_11_03_100150_guard_cloud_engine_node_identity.php` | Additive infrastructure schema/index: 2026 11 03 100150 guard cloud engine node identity. |
| `website/database/migrations/2026_11_03_100200_create_cloud_engine_operations.php` | Additive infrastructure schema/index: 2026 11 03 100200 create cloud engine operations. |
| `website/database/migrations/2026_11_03_100300_create_engine_telemetry_tables.php` | Additive infrastructure schema/index: 2026 11 03 100300 create engine telemetry tables. |
| `website/database/migrations/2026_11_03_100310_create_engine_traffic_months.php` | Additive infrastructure schema/index: 2026 11 03 100310 create engine traffic months. |
| `website/database/migrations/2026_11_03_100400_create_cloud_engine_api_requests.php` | Additive infrastructure schema/index: 2026 11 03 100400 create cloud engine api requests. |
| `website/lang/en/cloud_engine.php` | Matching localized permissions, messages and interface labels. |
| `website/lang/en/panel.php` | Matching localized permissions, messages and interface labels. |
| `website/lang/fa/cloud_engine.php` | Matching localized permissions, messages and interface labels. |
| `website/lang/fa/panel.php` | Matching localized permissions, messages and interface labels. |
| `website/lang/tr/cloud_engine.php` | Matching localized permissions, messages and interface labels. |
| `website/lang/tr/panel.php` | Matching localized permissions, messages and interface labels. |
| `website/public/assets/js/cloud-engine-account.js` | Existing application integration seam: cloud engine account. |
| `website/resources/content/developers.php` | Existing design-system integration, safe customer controls or API permission documentation. |
| `website/resources/views/account/cloud-server.blade.php` | Existing design-system integration, safe customer controls or API permission documentation. |
| `website/resources/views/account/partials/cloud-engine.blade.php` | Existing design-system integration, safe customer controls or API permission documentation. |
| `website/resources/views/admin/cloud-engine.blade.php` | Existing design-system integration, safe customer controls or API permission documentation. |
| `website/resources/views/admin/layout.blade.php` | Existing design-system integration, safe customer controls or API permission documentation. |
| `website/routes/cloud-engine-account.php` | Scoped routing or bounded worker/health scheduling. |
| `website/routes/cloud-engine-admin.php` | Scoped routing or bounded worker/health scheduling. |
| `website/routes/cloud-engine-internal.php` | Scoped routing or bounded worker/health scheduling. |
| `website/routes/cloud-engine.php` | Scoped routing or bounded worker/health scheduling. |
| `website/routes/console.php` | Scoped routing or bounded worker/health scheduling. |
| `website/routes/web.php` | Scoped routing or bounded worker/health scheduling. |
| `website/tests/Feature/CloudEngineApiTest.php` | Behavioral, protocol, fixture or concurrency verification: Cloud Engine Api Test. |
| `website/tests/Feature/EngineAdminTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Admin Test. |
| `website/tests/Feature/EngineBillingLifecycleTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Billing Lifecycle Test. |
| `website/tests/Feature/EngineCustomerPanelTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Customer Panel Test. |
| `website/tests/Feature/EngineIpamTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Ipam Test. |
| `website/tests/Feature/EngineOperationsTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Operations Test. |
| `website/tests/Feature/EnginePlacementTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Placement Test. |
| `website/tests/Feature/EngineTelemetryTest.php` | Behavioral, protocol, fixture or concurrency verification: Engine Telemetry Test. |
| `website/tests/Fixtures/EngineInventory.php` | Behavioral, protocol, fixture or concurrency verification: Engine Inventory. |
| `website/tests/js/cloud-engine-account.test.cjs` | Behavioral, protocol, fixture or concurrency verification: cloud engine account.test. |
| `website/tests/standalone/cloud-engine-mariadb-race.php` | Behavioral, protocol, fixture or concurrency verification: cloud engine mariadb race. |
| `website/tests/standalone/cloud-engine-placement-race.php` | Behavioral, protocol, fixture or concurrency verification: cloud engine placement race. |
