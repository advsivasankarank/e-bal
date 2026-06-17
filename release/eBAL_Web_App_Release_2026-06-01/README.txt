e-BAL Web Application - Release Package
Release Date: 2026-06-01

This folder contains the production handoff package for the hosted e-BAL web application.

Included
1. eBAL_Web_App_Release_2026-06-01.zip
   Deployable application package

2. ebal_db.sql
   Main database export

3. license_schema.sql
   Licensing/commercial schema reference

4. .cpanel.yml
   cPanel deployment file

5. INSTALLATION_GUIDE.txt
   Deployment and setup steps

6. RELEASE_NOTES.txt
   Release summary

7. VERSION.txt
   Version reference

Recommended Use
- Upload the zip for archival/release tracking
- Deploy app files to the server document root using Git/cPanel or manual upload
- Import ebal_db.sql into the production database
- Apply environment values for production database and bridge token
