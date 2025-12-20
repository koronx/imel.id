<?php
// Google OAuth Configuration
// Untuk production, simpan credentials ini di environment variables

return [
    'client_id' => getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_GOOGLE_CLIENT_SECRET',
    'redirect_uri' => getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/oauth-callback',
];
