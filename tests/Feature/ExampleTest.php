<?php

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
});