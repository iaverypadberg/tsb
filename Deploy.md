# About Deployment

## Claude is not allowed to write to this file


### OSM2PGSQL Payload with Github CI workflow
We're using PostgreSQL 18 here and Open Street Maps data does not natively ship in that form. In order to get it into that form we need [OSM2PGSQL](https://osm2pgsql.org/)

There is a GitHub CI pipeline which runs upon deployment of the application which builds the OSM table in a osm2pgsql infused docker image and then posts it to github as a new release. Once that is complete the app has a build script to load the payload into pgsql.
