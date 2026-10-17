<?php

/*
|--------------------------------------------------------------------------
| Route groups shared with the browser
|--------------------------------------------------------------------------
|
| Ziggy puts a list of route addresses into every page so the front end can build links. A signed-in
| user gets all of them. A signed-out visitor gets only the groups below (see
| resources/views/app.blade.php), so the login page does not list the /admin and /curate addresses.
|
| Each name here is one a signed-out page's components call route() with: the public header and
| front page, the auth layouts and pages, and the events, channels and submit pages. When a
| component reachable while signed out starts using another route, add its name here.
|
*/

return [
    'groups' => [
        // Always shared with a signed-out visitor: signing in, and the front-page link the auth layouts use.
        'sign_in' => [
            'home',
            'login',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'invitation.show',
            'invitation.store',
        ],
        // Also shared when the site has public pages (the invite-only gate off).
        'public' => [
            'events.index',
            'channels.index',
            'channels.show',
            'submit.create',
            'submit.store',
            'route66.index',
            'route66.show',
            'markets.index',
            'data.index',
            'art.index',
            'overview.index',
            'city.index',
            'earthquakes.index',
            'groundwater.index',
            'airquality.index',
            'garden.index',
            'climate.index',
        ],
    ],
];
