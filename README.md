# 2re-map

A small PHP 8.3 + SQLite web app that shows people on an interactive 3D globe.
Each user registers and places a pin with their **name, needs, skills, location,
notes** and an optional **picture**. Clicking a pin opens a floating card with the details.

- Bootstrap 5.3 dark theme, Bootstrap Icons
- [globe.gl](https://globe.gl) (three.js) for the globe; textures are bundled in `public/assets/img`
- City search and reverse geocoding via OpenStreetMap Nominatim (proxied and cached server-side)
- No build step, no Composer dependencies

## Requirements

PHP 8.3 with the extensions `pdo_sqlite`, `mbstring`, `fileinfo`, and either `curl`
or `allow_url_fopen`. `gd` is optional: when it is available, uploaded pictures are
resized to 480 px and re-encoded, which also strips EXIF/GPS metadata.

## Run locally

```bash
php -S localhost:8000 -t public
```

Then open http://localhost:8000. The SQLite database is created automatically at `data/app.sqlite`.

## Deploy

- Point the web server's document root at `public/`. `data/` and `src/` must not be web-accessible
  (they also contain `.htaccess` deny rules for Apache).
- The web server user needs write access to `data/` and `public/uploads/`.
- Set the `NOMINATIM_EMAIL` environment variable to a contact address. It is sent in the
  User-Agent header, as the [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/) asks.

## Structure

```
public/
  index.php        page: navbar, globe, modals
  api.php          JSON API (pins, auth, profile, geocoding)
  assets/app.js    globe, pin card, profile editor, location picker
  assets/app.css   dark theme styles
  assets/img/      globe textures
  uploads/         user pictures (git-ignored)
src/bootstrap.php  config, session, SQLite schema/migrations, helpers
data/              SQLite database (git-ignored)
```

## API

| Method | `api.php?action=` | Description |
|---|---|---|
| GET  | `pins`           | All users that have a location (public fields only, never the email) |
| GET  | `me`             | The logged-in user |
| GET  | `geocode&q=`     | City search |
| GET  | `reverse&lat=&lng=` | Place name for coordinates |
| POST | `register`, `login`, `logout` | Authentication |
| POST | `profile`        | Save name, needs, skills, location, notes, picture |
| POST | `delete_account` | Delete the account, its pin and its picture |

All POST requests require the `X-CSRF-Token` header (the token is embedded in the page).
