# Audit of the 68 baseline failures — 2026-10-10

All 68 original failing cases were rerun successfully (1,038 assertions, zero failures, errors or skips). One test was renamed to reflect the already implemented completed-response replay contract; its original identity is retained below. No old failing case was excluded.

The complete PHP runtime alone resolved 26 cases. The remaining fixes distinguish application defects from stale fixtures, default-locale assumptions, and outdated test expectations. Production money arithmetic and database uniqueness constraints were preserved.

| Original test | Cause and correction | Result |
|---|---|---|
| `AccountPanelTest::test_the_panel_is_closed_to_visitors` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `AdminBusinessReportTest::test_an_invalid_window_falls_back_to_the_default` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `AiCallerTest::test_duplicate_idempotency_key_never_calls_upstream_twice` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. Assert successful saved-response replay, one upstream request and one ledger debit; add separate in-flight 409 coverage. | PASS |
| `AiCallerTest::test_insufficient_funds_fails_before_any_http` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `AiCallerTest::test_provider_without_live_calls_is_rejected_before_any_http` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `AiCallerTest::test_success_settles_full_reservation` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `AiCallerTest::test_unpriced_model_never_reserves_and_never_calls` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `AiCallerTest::test_upstream_5xx_releases_reservation` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `AiPricingTest::test_charge_vector_output_tokens_round_up_once` | Correct the independent arithmetic vector: ceil(245000 × 1234567 / 1000000) = 302469. | PASS |
| `AiPricingTest::test_currency_is_preserved_per_historic_row` | Freeze time and advance one day before the replacement price; retain the original historical price/currency assertions. | PASS |
| `AiPricingTest::test_old_price_reproduces_historical_result_after_price_change` | Freeze time and advance one day before the replacement price; retain the original historical price/currency assertions. | PASS |
| `AiProjectsAndAdmissionTest::test_admission_denies_archived_and_missing_project` | Application: report project_missing for a deleted-project AI key; still deny access and leave use_count unchanged. | PASS |
| `AiProjectsAndAdmissionTest::test_budget_window_boundaries` | Freeze the test at its explicit November 2026 reference date. | PASS |
| `AiProjectsAndAdmissionTest::test_monthly_period_without_budget_rejected` | Load PHP intl for translated validation messages. | PASS |
| `AiProjectsAndAdmissionTest::test_project_create_budget_monthly` | Load PHP intl and submit budget_period=monthly, matching the actual form contract. | PASS |
| `Audit6RegressionTest::test_order_page_has_product_aggregate_offer_schema_and_signed_cycle_links` | Reject price query parameters on rendered checkout links; do not confuse legitimate data-price display attributes with request parameters. | PASS |
| `BaleKycReviewTest::test_approving_from_bale_equals_the_panel` | Load PHP GD for generated image/upload fixtures. | PASS |
| `BaleKycReviewTest::test_rejecting_from_bale_requires_a_reason` | Load PHP GD for generated image/upload fixtures. | PASS |
| `BaleKycReviewTest::test_the_docs_button_sends_the_actual_files` | Load PHP GD for generated image/upload fixtures. | PASS |
| `BaleKycReviewTest::test_the_kyc_notification_carries_review_buttons_and_no_link` | Load PHP GD for generated image/upload fixtures. | PASS |
| `BaleReplyButtonsTest::test_polish_never_sends_a_message_by_itself` | Load PHP curl. Application additionally returns no content before any network call when provider configuration is incomplete. | PASS |
| `BankAccountStorageTest::test_the_bank_is_detected_from_the_card_prefix` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CloudCatalogTest::test_admin_cloud_page_renders_and_shows_real_provider_names` | Retain actual provider-name assertions; replace obsolete numbered-provider label with the actual connection-settings link. | PASS |
| `CloudConsoleToolbarTest::test_the_console_shows_a_back_link_to_the_server_page` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CloudDeliveryReadinessTest::test_cloud_email_says_where_the_root_password_is_and_links_to_the_service_page` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CloudDeliveryReadinessTest::test_the_ssh_guide_is_linked_when_the_article_exists` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CloudPoweredOffStillBilledTest::test_a_stopped_hourly_server_says_billing_continues` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CloudServiceRowTest::test_windows_server_shows_rdp_administrator_and_no_fake_ssh` | Retain Windows RDP/Administrator and no-fake-SSH assertions; verify the available Arvan console link instead of an obsolete unavailable notice. | PASS |
| `CloudStoreLocationTest::test_a_cityless_code_is_never_merged_into_the_real_capital` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `ContentPipelineTest::test_thursday_and_friday_are_the_light_days` | Check weekend baseQuotaFor=2; calibrated plan quotas are separately tested for bounds and year-end capacity. | PASS |
| `CountryLinkSweepTest::test_every_country_page_shows_plans_or_says_why_not` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CountryLinkSweepTest::test_the_catch_all_index_shows_country_cards` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `CustomerEmailLocalizationTest::test_kyc_result_email_is_branded_localized_and_iran_free` | Load PHP GD for generated image/upload fixtures. | PASS |
| `CustomerTunnelAccountTest::test_customer_can_issue_an_account_and_key_is_never_stored` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_customer_can_remove_an_account_and_gets_the_router_command` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_duplicate_name_and_ip_are_rejected` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_generated_keys_match_the_reference_derivation` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_ip_defaults_to_the_next_free_address` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_issued_config_carries_the_servers_own_parameters` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_legacy_config_uses_the_old_schema` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `CustomerTunnelAccountTest::test_the_issued_block_renders_after_the_redirect` | Load PHP sodium; retain real key-derivation and generated-configuration assertions in the isolated database. | PASS |
| `ForeignKycTest::test_a_card_id_requires_its_back_side` | Load PHP GD for generated image/upload fixtures. | PASS |
| `ForeignKycTest::test_a_complete_submission_goes_to_the_review_queue` | Load PHP GD for generated image/upload fixtures. | PASS |
| `ForeignKycTest::test_admin_approval_verifies_and_opens_the_iran_gate` | Load PHP GD for generated image/upload fixtures. | PASS |
| `ForeignKycTest::test_minors_are_rejected` | Load PHP GD for generated image/upload fixtures. | PASS |
| `ForeignKycTest::test_the_review_queue_shows_who_and_which_country` | Load PHP GD for generated image/upload fixtures. | PASS |
| `ForeignPanelLocaleTest::test_kyc_flash_is_localized_and_dashboard_links_to_verification` | Load PHP GD for generated image/upload fixtures. | PASS |
| `GpuDeliveryEmailTest::test_an_ordinary_cloud_server_keeps_its_password_guidance` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `GpuDeliveryEmailTest::test_it_says_what_the_customer_should_actually_look_for` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `GpuDeliveryEmailTest::test_the_gateway_box_wins_when_both_flags_are_set` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `HourlyHonestyTest::test_an_hourly_service_is_labelled_hourly_not_monthly` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `HourlyVpsPageTest::test_the_hourly_rate_on_the_page_is_the_model_rate_not_a_hand_typed_number` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `MenuIsManageableTest::test_a_persian_only_link_is_absent_from_the_other_languages` | Application: construct footer links and headings in the requested locale, then restore the caller locale. Add cross-locale configured/custom-link coverage. | PASS |
| `ReplyPolishNeverSendsTest::test_a_missing_ai_key_says_so_instead_of_crashing` | Load PHP curl. Application additionally returns no content before any network call when provider configuration is incomplete. | PASS |
| `ResellerPanelPagesTest::test_a_404_after_a_parameterised_route_stays_a_404` | Load PHP zip; retain archive download, path and 404 assertions. | PASS |
| `ResellerPanelPagesTest::test_the_whmcs_module_downloads_as_a_zip_with_the_right_paths` | Load PHP zip; retain archive download, path and 404 assertions. | PASS |
| `ResellerPanelPagesTest::test_the_wordpress_plugin_downloads_with_the_right_folder` | Load PHP zip; retain archive download, path and 404 assertions. | PASS |
| `TicketAttachmentTest::test_customer_can_open_ticket_with_image_attachment` | Load PHP GD for generated image/upload fixtures. | PASS |
| `TicketTest::test_tickets_are_closed_to_visitors` | Pin the isolated PHPUnit default locale to fa, matching the Persian root routes; explicit English/Turkish tests remain enabled. | PASS |
| `V1ChatTest::test_duplicate_idempotency_key_calls_upstream_once_and_409` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. Assert successful saved-response replay, one upstream request and one ledger debit; add separate in-flight 409 coverage. | PASS |
| `V1ChatTest::test_empty_wallet_is_402_insufficient_funds` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_happy_path_returns_upstream_body_verbatim` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_missing_bearer_header_is_401_invalid_token` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_missing_model_field_is_422_invalid_payload` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_non_ai_key_denies_with_not_ai_key` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_revoked_token_denies_admission_with_verbatim_code` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_unknown_token_is_401_invalid_token` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |
| `V1ChatTest::test_upstream_5xx_maps_to_502_upstream_error` | Reuse the migration-seeded DeepInfra provider; keep the unique slug constraint. | PASS |

See [TESTING.md](TESTING.md) for reproducible runtime and database isolation requirements. Production deployment details and service 20 verification are in the root change record.
