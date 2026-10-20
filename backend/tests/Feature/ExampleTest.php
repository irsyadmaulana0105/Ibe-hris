<?php

it('sends guests at the backend root to login', function () {
    // Rute '/' terakhir ada di grup auth (routes/web.php), jadi tamu ke login dulu.
    $this->get('/')->assertRedirectContains('login');
});
