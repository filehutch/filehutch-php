<?php

use FileHutch\Laravel\Http\DirectUploadController;
use Illuminate\Support\Facades\Route;

Route::post('uploads', [DirectUploadController::class, 'create'])->name('filehutch.uploads.create');
Route::post('uploads/{id}/complete', [DirectUploadController::class, 'complete'])->name('filehutch.uploads.complete');
