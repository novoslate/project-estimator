# Project Estimator

WordPress plugin for instant price estimators with lead capture. See `readme.txt` for setup.

## Releasing an update

1. Bump `Version:` in `project-estimator.php` and `NSE_VERSION` (and `Stable tag` in `readme.txt`).
2. Run `ship` from this folder (the global script in ~/bin). It unzips the newest `project-estimator-v*.zip`
   from ~/Downloads into ~/Projects, shows the changes, asks to confirm, then commits, tags, and pushes.
3. GitHub Actions builds `project-estimator.zip` and attaches it to the release.
   Client sites see the update in Dashboard > Updates within about 12 hours (or click "Check for updates" on the Plugins screen).
