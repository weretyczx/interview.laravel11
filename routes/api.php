<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json('pong');
});

Route::group(['middleware' => ['auth:user', 'jwt.auth']], function ($router) {
    $router->post('/order', 'OrderController@store')->middleware('throttle:order');
    // ->middleware('throttle:10,1');
});
