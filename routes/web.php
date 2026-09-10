<?php
use Illuminate\Support\Facades\Route;
Route::get('/', fn () => response()->json(['success' => true, 'message' => 'VibeLocate Laravel API is running']));
