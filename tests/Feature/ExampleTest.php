<?php

it('redirects home to the admin panel', function () {
    $response = $this->get('/');

    $response->assertRedirect('/admin');
});
