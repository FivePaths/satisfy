# Satisfy [![Build Status](https://travis-ci.org/ludofleury/satisfy.svg)](https://travis-ci.org/ludofleury/satisfy)

[Satis Composer repository manager](http://getcomposer.org/doc/articles/handling-private-packages-with-satis.md) with a simple web UI.

<p>
  <img src="http://project-satisfy.github.io/satisfy/images/list.png" width="280" height="235" alt="Satis: list composer repositories"/>
  <img src="http://project-satisfy.github.io/satisfy/images/create.png" width="280" height="235" alt="Satis: add a new composer repository"/>
  <img src="http://project-satisfy.github.io/satisfy/images/update.png" width="280" height="235" alt="Satis: update an existing composer repository"/>
</p>

## Introduction

Satisfy provides:

* a Web UI: A CRUD to manage your satis configuration file
* a simple security layer with a login form
* a webhook endpoints for the most popular version control systems
* Satis itself
* optional plugins, enabled with `SATISFY_PLUGINS`: per-token access to the catalogue with per-package grants (`authz`), and a registration gate and publishing on tag for GitLab system hooks (`hookgate`). See [docs/plugins.md](docs/plugins.md)

Satisfy ease your satis configuration management. It provides a simple web UI over Satis to avoid a hand-editing of the satis.json configuration file. All repositories from a composer.lock file can also be imported via upload.


## How it works?

Basically, it just reads/writes the satis.json file and provides a web CRUD.

* On each HTTP request, the satis.json is loaded.
* If a write occurs in this file, a backup is made in var/satis/

## Installation

### Composer

* Clone project `git clone https://github.com/project-satisfy/satisfy.git`
* Download composer `wget http://getcomposer.org/composer.phar`
* Install `php composer.phar install -n`

### Satis configuration

* Either define your [default/existing satis configuration](http://getcomposer.org/doc/articles/handling-private-packages-with-satis.md)
* or use interactice satis CLI tool `./bin/satis init`
* or submit form at /admin/configuration

By default, the `satis.json` file is expected at the project root, but You can set another path under the parameter `satis_filename`.

### Application configuration

* Customize `app/config/parameters.yml` according to your needs.

### Security

You can restrict the access setting `admin.auth` parameter (in `app/config/parameters.yml`) to `true`. Set authorized users in `admin.users` config array.

## Run Satisfy

Create a web server pointing to the `public` directory. Browse to »/admin/« to manage the `satis.json`. To serve the `package.json` it is required to run Satis first.

### Satis packages definition generation

Since Satisfy does only manages the Satis configuration file, it is necessary to build the package definitions using Satis.

A bin shortcut to Satis is already included in Satisfy, so run the following command to generate the files in the web folder.

 ```
./bin/satis build
```

### Automatically build a single package using WebHooks

For example, you can trigger a package generation for your BitBucket project by setting up a BitBucket webhook to connect back to [your-satis-url]/webhook/bitbucket every time you do a code push. This is more efficient than doing a full build and avoids you having to run full builds on a frequent schedule or logging in to the admin interface just to force a build.

A delivery for a repository in `satis.json` queues a build of that repository, and is answered `202 queued`; the worker runs the build.

| Provider | Endpoint | Secret | Checked against |
|---|---|---|---|
| GitLab | `/webhook/gitlab` | `GITLAB_SECRET` | `X-Gitlab-Token` |
| GitHub | `/webhook/github` | `GITHUB_SECRET` | `X-Hub-Signature-256`; JSON or form-encoded |
| Gitea | `/webhook/gitea` | `GITEA_SECRET` | `X-Gitea-Signature` |
| Bitbucket Cloud | `/webhook/bitbucket` | `BITBUCKET_SECRET` | `X-Hub-Signature`; without a secret, Atlassian's published addresses |
| Azure DevOps | `/webhook/devops` | `DEVOPS_SECRET` | An `X-DEVOPS-TOKEN` header added under the service hook's "HTTP headers"; "Resource details to send" must be All |

GitLab, GitHub and Gitea can also add a repository `satis.json` does not list yet: see the `*.auto_add_repo` parameters in `config/services.yaml`.

## Prebuilt docker image

You can run satisfy using prebuilt docker image. Here is an example how to setup it.

1. Create dir for configuration files
2. Add parameters.yml file, can be copied from config/parameters.yml.dist
3. Add auth.json with all required composer authentication tokens
4. Add simple satis.json with basic information
5. Create docker-compose.yml using example below
6. Start containers `docker compose up`
7. Open `http://localhost:8000/admin`, and queue a full build from the build page, or run `docker compose exec -u www-data satisfy bin/console satisfy:rebuild`
8. Open satis page on `http://localhost:8000`

```
services:
  satisfy:
    image: ghcr.io/project-satisfy/satisfy:latest
    ports:
      - "${APP_PORT:-8000}:80"
    volumes:
      - ./satis.json:/var/www/html/satis.json
      - ./parameters.yml:/var/www/html/config/parameters.yml
      - ./auth.json:/var/www/.composer/auth.json:ro
```

The image runs nginx, which serves the built catalogue as files; php-fpm, which serves the admin UI and the webhooks; and the worker, which runs the builds that the webhooks and the admin UI queue. The first argument picks what runs: `all` (the default), `web`, `worker`, or `rebuild` to build once and exit.

### Running the web server and the worker apart

Only the worker needs Composer's credentials. Run it in a container of its own, and nothing that answers HTTP requests can read them:

```
services:
  web:
    image: ghcr.io/project-satisfy/satisfy:latest
    command: web
    ports:
      - "${APP_PORT:-8000}:80"
    volumes:
      - ./satis.json:/var/www/html/satis.json
      - ./parameters.yml:/var/www/html/config/parameters.yml
      - output:/var/www/html/public
      - queue:/var/www/queue
  worker:
    image: ghcr.io/project-satisfy/satisfy:latest
    command: worker
    volumes:
      - ./satis.json:/var/www/html/satis.json
      - ./parameters.yml:/var/www/html/config/parameters.yml
      - ./auth.json:/var/www/.composer/auth.json:ro
      - output:/var/www/html/public
      - queue:/var/www/queue

volumes:
  output:
  queue:
```

Share the output directory and the queue between the two, and nothing else under `/var/www/html/var`.

### Behind a reverse proxy

Set `TRUSTED_PROXIES` to the proxy's address or range, such as the Docker network's (`172.18.0.0/16`), or to `private_ranges`. Satisfy then builds `https://` URLs when the proxy sends `X-Forwarded-Proto: https`, and its logs give the client's address from `X-Forwarded-For`.

## Authors

* Ludovic Fleury - <ludo.fleury@gmail.com> - <http://twitter.com/ludofleury>
* Julius Beckmann - <satisfy@h4cc.de> - <https://twitter.com/h4cc>
* Ramūnas Dronga - <satisfy@ramuno.lt>

## License

Satisfy is licensed under the MIT License - see the LICENSE file for details

