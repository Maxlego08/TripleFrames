<?php

test('the retired player dashboard no longer exists', function () {
    expect(route('profile.edit', absolute: false))->toBe('/settings/profile');

    $this->get('/dashboard')->assertNotFound();
});
