<?php

return [
    'ai' => [
        'title' => 'AI API, hourly GPU or n8n: which fits your project?',
        'intro' => 'Start with the result you need: a response from a hosted model, your own code and model on a GPU, or a workflow connecting several services. Each choice has different running costs and maintenance responsibilities.',
        'cards' => [
            ['title' => 'A hosted model API for your application', 'text' => 'For chat, summarization or semantic search, choose a ready model for that operation. Check its pricing unit, input limits and usage calculator. An available model does not necessarily support every operation.', 'route' => 'ai.models', 'link' => 'Compare models and estimate usage'],
            ['title' => 'A GPU for your own code and model', 'text' => 'For PyTorch, custom model weights or GPU data processing, review the compatible GPU application. Choose suitable VRAM, keep files outside temporary storage and plan how to run and resume your workload.', 'route' => 'gpu', 'link' => 'Compare hourly GPU rental'],
            ['title' => 'n8n to connect a business workflow', 'text' => 'n8n can connect forms, CRM and an AI service into a workflow. Hosting and external API usage are separate costs. Store service keys in credentials rather than sharing them in a workflow export.', 'route' => 'cloud.location', 'parameters' => ['location' => 'n8n'], 'link' => 'Explore n8n hosting'],
        ],
        'checks' => ['Before funding, check the operation and available model; the key needs permission for that operation.', 'Keep the first request short with a small output cap. Review its request identifier and actual charge in usage history.', 'Create one new Idempotency-Key per logical request. A retry of the same request must keep the same key and body.'],
    ],
    'gpu' => [
        'title' => 'Getting started with a GPU: choose the app and keep your results',
        'intro' => 'Choose the workload before the graphics card. Hourly GPU provides a ready application environment; check its memory requirements and access method before ordering.',
        'cards' => [
            ['title' => 'Image workflows with ComfyUI', 'text' => 'Check that your model and workflow fit the ready application. Plugins and interfaces from other distributions are not automatically included. Start with a small output and save the result.', 'route' => 'blog', 'parameters' => ['slug' => 'comfyui-hourly-gpu-guide'], 'link' => 'Read the ComfyUI getting-started guide'],
            ['title' => 'Python and PyTorch with Jupyter', 'text' => 'Prepare your code and dependencies for analysis or model work. Before a long job, check CUDA detection, disk space and a way to save checkpoints outside the environment.', 'route' => 'blog', 'parameters' => ['slug' => 'jupyter-hourly-gpu-guide'], 'link' => 'Read the Jupyter and checkpoint guide'],
            ['title' => 'Only need responses from a hosted model?', 'text' => 'Compare the AI API if your application needs a ready model. Check the model usage unit for API calls; for a GPU, also consider environment runtime and the work of maintaining the job.', 'route' => 'ai.home', 'link' => 'Explore the AI API'],
        ],
        'checks' => ['Check starting credit, the displayed rate, the selected application and readiness status in your account.', 'This service does not provide a traditional VPS root password or public SSH access. Confirm the application HTTPS path and limits before designing an integration.', 'Save files externally. To end usage, stop or cancel through your account and confirm the resulting state.'],
    ],
    'python' => [
        'title' => 'From Django or Flask code to a production web app',
        'intro' => 'Before choosing Python hosting, identify the runtime, database and background processes your app needs. A small, verifiable application helps separate deployment problems from application errors.',
        'cards' => [
            ['title' => 'Deploy WSGI one step at a time', 'text' => 'The virtual environment, dependencies and application entry point need to agree. Follow the Django and Flask guide for a small health endpoint and a release checklist.', 'route' => 'blog', 'parameters' => ['slug' => 'python-hosting-django-flask-deployment'], 'link' => 'Read the Django and Flask deployment guide'],
            ['title' => 'Choose a plan for your actual requirements', 'text' => 'Compare the number of applications, RAM, file storage and MySQL/MariaDB database requirements. A file extension or framework name alone does not determine the resources you need.', 'fragment' => '#pricing', 'link' => 'Compare Python hosting plans'],
            ['title' => 'Consider a VPS for workers and WebSockets', 'text' => 'Persistent Celery workers, WebSockets, SSE and full OS control need a suitable runtime. Include the responsibility for managing that stack when comparing a VPS with shared hosting.', 'route' => 'vps.hourly', 'link' => 'Explore hourly VPS options'],
        ],
        'checks' => ['Turn DEBUG off before release. Keep secrets outside the repository and public directories.', 'Check HTTPS, static assets, database access and the health endpoint separately. A working homepage does not verify all of them.', 'For a 500 error, share the time, request path and a sanitized error with support. Do not send passwords or environment files.'],
    ],
    'n8n' => [
        'title' => 'Your first n8n webhook: verify it before connecting real work',
        'intro' => 'Once you have the HTTPS address, build a test workflow returning a fixed response. Keep messages, payments and customer-data changes out of this initial check.',
        'cards' => [
            ['title' => 'Separate the test and production URLs', 'text' => 'Select Listen for test event before calling the test URL. The production URL requires a published or active workflow. Copy the exact method and path from the Webhook node.', 'fragment' => '#n8n-faq', 'link' => 'Review n8n access and service conditions'],
            ['title' => 'Check the response and execution together', 'text' => 'Send synthetic input, verify the fixed response and find the same run under Executions. A 404 may indicate the wrong path, method or test/production mode. Check the HTTPS certificate as well.', 'route' => 'blog', 'parameters' => ['slug' => 'n8n-backup-migration-guide'], 'link' => 'Read the execution and migration guide'],
            ['title' => 'Rehearse restoration before important workflows', 'text' => 'Include the database, workflows, credentials and encryption key in a backup plan. Keep an external copy and test restoration. A workflow JSON export alone is not a complete backup.', 'route' => 'blog', 'parameters' => ['slug' => 'n8n-backup-migration-guide'], 'link' => 'Read the n8n backup checklist'],
        ],
        'checks' => ['Configure suitable authentication for sensitive public webhooks. A long URL is not an access-control policy.', 'Connected-service limits and charges, including AI API usage, are separate from the hosting price.', 'Workflow development and managed recurring backups are not promised in the base package. Read the support scope before ordering.'],
    ],
    'curl_note' => 'This example incurs real usage. Keep the key in an environment variable. Create the request identifier once per logical request and keep it when retrying that same request.',
];
