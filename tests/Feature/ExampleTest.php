<?php

declare(strict_types=1);

/** covers §4.2, §4.10, §6.2 */
it('sends guests from the root url to the login page', function (): void {
    $this->followingRedirects()
        ->get('/')
        ->assertOk()
        ->assertSee(route('login.store'), escape: false);

    $this->get('/')->assertRedirect(route('login'));
    $this->get('/dashboard')->assertRedirect(route('login'));
});
