<?php

return [
    'meta_title' => 'ServerNet AI Studio | Chat, Image & Video Generation',
    'meta_description' => 'Write, translate and create images, video and audio in ServerNet AI Studio. Compare AI models and use your ServerNet wallet for usage-based billing.',
    'title' => 'One idea, a thousand possibilities.',
    'accent' => 'All in ServerNet AI Studio.',
    'intro' => 'ServerNet AI Studio is your online workspace for talking to AI models, writing, learning and creating images, video and audio. Choose a model for the task and get started with your ServerNet account, without setting up GPU infrastructure yourself.',
    'open' => 'Start creating in Studio',
    'models' => 'Explore models and usage rates',
    'use_heading' => 'From everyday work to big ideas',
    'use_intro' => 'Choose a model for each task. Capabilities, output quality and cost depend on the selected model and operation.',
    'uses' => [
        'text' => ['title'=>'Chat, write and learn', 'text'=>'Draft emails and social posts, translate, summarize, brainstorm and get help with coding.'],
        'image' => ['title'=>'Create images and visual content', 'text'=>'Turn an idea into an image for concepts, product content, advertising and visual inspiration.'],
        'video' => ['title'=>'Make AI-generated video', 'text'=>'Turn visual ideas into clips with available models; check each model’s duration, resolution and limits before running it.'],
        'audio' => ['title'=>'Create audio and speech', 'text'=>'Convert text to speech or generate audio with compatible models. Supported languages and capabilities vary by model.'],
    ],
    'billing_title' => 'One account, clear usage billing',
    'billing_text' => 'Use your existing ServerNet account and wallet. Paid operations are charged according to model rates and usage; the model catalogue is the source for rates.',
    'browser_title' => 'In your browser, no complex installation',
    'browser_text' => 'Open the web version directly or install it as a web app in a compatible browser. An internet connection is required.',
    'choose_heading' => 'Studio, API or GPU?',
    'choose_intro' => 'Three different ways to work with AI. Choose according to the job you need to do.',
    'choices' => [
        'studio'=>['title'=>'Studio for direct use', 'text'=>'Create text, images, video and audio using an online interface, without deploying a model or building an application.', 'link'=>'Read common questions'],
        'api'=>['title'=>'AI Gateway for developers', 'text'=>'Integrate AI into your own software with model APIs, documentation, request limits and usage rates.', 'link'=>'Explore the AI Gateway'],
        'gpu'=>['title'=>'GPU for running your own workload', 'text'=>'When you need an environment such as Jupyter, ComfyUI or Ollama, compare available GPU and application combinations.', 'link'=>'Explore GPU rental'],
    ],
    'faq_heading' => 'Frequently asked questions about AI Studio',
    'faqs' => [
        ['q'=>'Do I need to rent a graphics card to use Studio?', 'a'=>'No. Studio is an online service for using available AI models. GPU rental is for work that needs your own compute application environment.'],
        ['q'=>'How is AI model usage priced?', 'a'=>'Paid operations are charged to your ServerNet wallet based on the model and amount of usage. Check the model catalogue for its rate and billing unit.'],
        ['q'=>'Can I use Studio without installing an application?', 'a'=>'Yes, the web version works in a compatible browser. Browser web-app installation is optional; the download page explains native installers and their release status.'],
        ['q'=>'Can every model produce every kind of content?', 'a'=>'No. Each model has its own capabilities, input and output types, limits and rates. Choose an available model that fits your task.'],
    ],
];
