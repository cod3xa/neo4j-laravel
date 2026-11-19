<?php

use App\Http\Controllers\MovieController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;

// Test route for database connection
Route::get('/test-connection', function (ClientInterface $client) {
    try {
        $result = $client->writeTransaction(function (TransactionInterface $tx) {
            return $tx->run('RETURN 1 as test');
        });

        return [
            'success' => true,
            'result' => $result->first(),
            'connection' => config('database.default'),
            'url' => config('database.connections.neo4j.url'),
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'connection' => config('database.default'),
            'url' => config('database.connections.neo4j.url'),
        ];
    }
});

// User routes
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users/{email}', [UserController::class, 'show']);
Route::put('/users/{email}', [UserController::class, 'update']);
Route::delete('/users/{email}', [UserController::class, 'destroy']);

// Movie routes
Route::get('/movies', [MovieController::class, 'index']);
Route::post('/movies', [MovieController::class, 'store']);
Route::post('/movies/actors', [MovieController::class, 'addActor']);
Route::get('/movies/{title}', [MovieController::class, 'show']);
Route::delete('/movies/{title}', [MovieController::class, 'destroy']);
Route::get('/movies/{title}/similar', [MovieController::class, 'findSimilar']);
