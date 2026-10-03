# Project Estimator

WordPress plugin for instant price estimators with lead capture. See `readme.txt` for setup.

## Releasing an update

1. Bump `Version:` in `project-estimator.php` and `NSE_VERSION` (and `Stable tag` in `readme.txt`).
2. Commit and push to `main`.
3. Tag and push:
   ```
   git tag v1.0.1
   git push origin v1.0.1
   ```
4. GitHub Actions builds `project-estimator.zip` and attaches it to the release.
   Client sites see the update in Dashboard > Updates within about 12 hours (or click "Check for updates" on the Plugins screen).
