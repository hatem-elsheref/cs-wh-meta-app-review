<?php

test('the root redirects to the SPA', function () {
    $response = $this->get('/');

    $response->assertRedirect('/spa');
});

test('the SPA shell is served', function () {
    $this->get('/spa')->assertOk();
});
