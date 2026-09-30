# Routes

The project is Laravel 12 + Inertia React. Routes are declared in PHP and resolve to React pages through controllers.

| URL | Name | Handler / page role | Layout |
| --- | --- | --- | --- |
| `/` | public home | `resources/js/pages/welcome.tsx` | Public layout |
| `/r/new` | `room.create` | `RoomController::create`, room creation | Public layout |
| `/r/{room}/join` | `room.entry` | `RoomEntryController::show`, seat entry | Public layout |
| `/r/{room}` | `room.show` | Lobby and active game state | Game layout |
| `/r/{room}/state` | `room.state` | Player-specific state packet | JSON |
| `/clock` | `clock.show` | Stateless game clock | JSON |
| `/f/{serveToken}` | `frame.serve` | Signed game frame image | Binary |

Relevant route declarations from `routes/game.php`:

```php
Route::get('r/new', [RoomController::class, 'create'])->name('room.create');
Route::post('r', [RoomController::class, 'store'])->name('room.store');
Route::get('r/{room}', [RoomController::class, 'show'])->name('room.show');
Route::get('r/{room}/join', [RoomEntryController::class, 'show'])->name('room.entry');
Route::post('r/{room}/join', [RoomEntryController::class, 'store'])->name('room.join');
Route::get('r/{room}/state', [RoomStateController::class, 'show'])->name('room.state');
Route::get('clock', [ClockController::class, 'show'])->name('clock.show');
Route::get('f/{serveToken}', [FrameServeController::class, 'show'])->name('frame.serve');
```

Standalone design routes are plain files under `design-test/html/`: `index.html`, `login.html`, `register.html`, `waiting-room.html`, and the new `game.html` target.
