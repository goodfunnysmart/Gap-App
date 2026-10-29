# Gap App

Gap App is a small Better Schools page for a parent, teacher, or learner who is stuck on one task. Show the task, confirm the Marble skill, say what is happening, then walk back through the prerequisite chain with a few quick checks. The page stops at the missing prior learning and maps three steps forward, ending back at the task.

Marks and photos stay in the browser for that visit. The server does not store the photo, the task text, or a student record. Feedback email is the only message it sends, and that message is about the app, not a student.

The look follows the Better Schools apps: the same logo files, navy `#042e5e`, Classic and Dark appearances, Lexend, and an amber focus ring. It is laid out for a phone.

## Project files

```text
Gap-App/
├── .cpanel.yml                   ← cPanel "Deploy HEAD Commit" tasks
├── deploy/
│   └── cpanel-deploy.sh          # Copies site files; keeps config.php and Directory Privacy
├── public/                       ← Deployed to $HOME/betterschools.com.au/gap-app
│   ├── index.html                # The five screens
│   ├── app.js                    # The flow. Task text is kept in memory only.
│   ├── app.css                   # Classic layout
│   ├── dark.css                  # Dark palette
│   ├── identify.php              # Photo or text → Marble topic ids
│   ├── plan.php                  # Deterministic prerequisite checks
│   ├── wording.php               # Optional parent-friendly wording
│   ├── feedback.php              # Feedback endpoint
│   ├── private_path.php          # Finds the private config outside the document root
│   ├── data/marble.json          # Built Marble index
│   ├── assets/                   # Better Schools logos and the sample task photo
│   └── fonts/                    # Lexend (SIL Open Font License)
├── private/                      ← Deployed to $HOME/gap-app-private
│   ├── bootstrap.php
│   ├── marble.php                # Search, prerequisite spine, checks, fallback wording
│   ├── gemini.php                # PHP 8.2 Gemini call. The key stays in private config.
│   ├── image.php                 # Re-encodes a photo so EXIF is dropped
│   ├── feedback_lib.php
│   └── config.example.php        # Placeholder key. FEEDBACK_TO is info@betterschools.com.au
├── source/
│   ├── build.py                  # Rebuilds public/data/marble.json
│   └── data/                     # Marble Skill Taxonomy v1, unchanged
└── tests/
```

## Flow

1. **Current challenge.** A task is required. Take a photo, paste the task, or describe it. Continue stays off until one of those is provided. The note asks for no names or faces.
2. **Is it one of these?** Two or three Marble skills in plain English, or None of these.
3. **What's happening?** Any of: don't know where to start, keep getting it wrong, don't understand the task, get stuck partway, takes ages, forgets it next day. Then Find the gap.
4. **Quick checks.** The Marble prerequisite chain is walked binary-search style, about three to five checks. Each one says Ask them, shows one tiny check and a short answer, then Got it or Not yet. The "what's happening" choices move the starting point.
5. **Start here.** The gap skill, one Try this activity, and Then next: three steps forward, the last of which is back at the task. Re-check later runs the checks again.

Curriculum codes, the reason for the link, and the full path sit behind (i). They are not on the card itself.

The sample task is `public/assets/sample-task.png`: a square with area 81 square centimetres, find the side. Use **Try the sample task** on the first screen. Uploading that same file is recognised without the AI key.

## Data

Mathematics, English, Science, History, Geography, Computing, Personal & Social Development, Life Skills, and Learning to Learn all come from Marble Skill Taxonomy v1. Skills tagged with Australian Curriculum v9 codes (Marble `standards` entries prefixed `acara-v9:`) keep those codes behind (i). Other standards on a skill are not shown.

The prerequisite walk is computed from the Marble edges. It does not ask the model to invent the chain. The model, when a key is configured, only reads the task and rewrites check wording inside the ids it was given.

Rebuild after a taxonomy change:

```bash
python3 source/build.py
```

Run that from the repository root. It writes `public/data/marble.json`. Commit that built output with the source.

## AI

`identify.php` and `wording.php` call Gemini the same way as Comment Drafter: PHP cURL, HTTPS only, model `gemini-3.5-flash-lite`, key from `GEMINI_API_KEY` in the private config or the server environment. The key is sent as a header and is never written into the page, the repository, or a log line.

A photo is re-encoded in memory before it is sent, which drops EXIF. It is not saved. Task text is not saved. Lines that look like a name, school, or class heading are removed before matching. The prompt tells the model to ignore names and faces.

Candidate topic ids are filtered to ids that exist in the Marble index. Before that call, the server keeps only a short keyword shortlist so the prompt stays small.

Without a key, paste and describe still work: topics are chosen by keyword search, and each check uses that topic's Marble `assessmentPrompt` and description. Photo reading of a new picture needs the key. The bundled sample photo does not.

## Students

Do not type a student's name, a school, or a class code. The page says so on the first screen. Nothing about a student is posted except the task itself, and that text is discarded when the request finishes. Feedback cannot include a task, a photo, or a student field.

## Feedback

A feedback link sits with the Marble credit. A wrong-path note can also be sent from (i). Both POST JSON to `feedback.php`. The endpoint checks the same origin, a CSRF token, a honeypot field, and a rate limit. It does not store the message. Mail is sent from `noreply@betterschools.com.au`.

The recipient is `FEEDBACK_TO` in the private config. The example, and the default to copy onto the server, is the public address `info@betterschools.com.au`.

## Marble credit

Visible on every screen, and included in the built data file and in each feedback message:

Marble Skill Taxonomy (v1) · © Generative Spark, Inc. (Marble) · https://withmarble.com · ODbL 1.0 (database) and CC BY-SA 4.0 (content) · https://github.com/withmarbleapp/os-taxonomy

This is a starting point, not a diagnosis. Ages are approximate and the taxonomy is not written for learners with additional needs. Curriculum standards referenced by the taxonomy have their own upstream licences (see PROVENANCE.md in that repository).

## Local preview

PHP 8.2 or newer, with `curl`, `gd`, and `mbstring`.

```bash
python3 source/build.py
php -S 127.0.0.1:8765 -t public
```

Open http://127.0.0.1:8765 . To try feedback or photo reading locally, copy `private/config.example.php` to `private/config.php` and set `GEMINI_API_KEY` there. Git ignores `config.php`. Do not commit that file.

## Checks

```bash
python3 source/build.py
php tests/marble_check.php
php tests/privacy_check.php
php tests/feedback_check.php
bash tests/deploy_check.sh
```

## Deploy on cPanel

cPanel clones this repository **outside** the website. **Deploy HEAD Commit** runs `.cpanel.yml`, which runs `deploy/cpanel-deploy.sh`. The script uses `$HOME`, so the account name is not stored in Git. The document root for betterschools.com.au is `$HOME/betterschools.com.au`, not `$HOME/public_html`.

The script copies `public/` to `$HOME/betterschools.com.au/gap-app` and `private/` to `$HOME/gap-app-private`. `config.php` is never created, copied, or deleted. A Directory Privacy block marked `cp:ppd` in the live `.htaccess` is kept. The live address is `https://betterschools.com.au/gap-app`.

Do not clone into `betterschools.com.au` or `public_html`. Do not point the domain’s document root at the clone or at `gap-app-private`.

### One-time setup

Use the cPanel account that serves betterschools.com.au.

**1. Clone outside the website**

1. Open **cPanel → Files → Git Version Control → Create**.
2. Leave **Clone a Repository** on.
3. Clone URL: `https://github.com/goodfunnysmart/Gap-App.git` (or the SSH URL if a deploy key is already in use).
4. Repository Path: `repositories/Gap-App`. cPanel creates `$HOME/repositories/Gap-App`. Do not use a path inside `betterschools.com.au` or `public_html`.
5. Repository Name: `Gap App`.
6. Click **Create**.

**2. First deploy**

1. **Git Version Control → Manage** for Gap App.
2. Set **Checked-Out Branch** to `main` and click **Update** if another branch is selected.
3. Open **Pull or Deploy**.
4. Click **Update from Remote**, then **Deploy HEAD Commit**.

That creates:

```text
$HOME/betterschools.com.au/gap-app/        the website
$HOME/gap-app-private/                     bootstrap, Marble helpers, example config
$HOME/gap-app-private/throttle/            rate-limit timestamps only, mode 0700
```

**3. Private config**

1. In File Manager, open `$HOME/gap-app-private/` and confirm it is not inside `betterschools.com.au` or `public_html`.
2. Copy `config.example.php` to `config.php` in that same directory.
3. Set `GEMINI_API_KEY` to the Gemini key. Leave `GEMINI_MODEL` as `gemini-3.5-flash-lite` unless you have a reason to change it.
4. `FEEDBACK_TO` is already `info@betterschools.com.au`. That public Better Schools address is the default. Change it in this private `config.php` only if feedback should go somewhere else. Do not commit that change.
5. Leave `FEEDBACK_FROM` as `noreply@betterschools.com.au`. The host must allow that envelope sender.
6. Set the file mode to `600`. Deploy will not overwrite `config.php`.
7. Click **Deploy HEAD Commit** again so permissions and `.htaccess` are refreshed.

You can set `GEMINI_API_KEY` and `GEMINI_MODEL` in the server environment instead of in `config.php`. An empty or placeholder key turns photo reading off. Paste, describe, and the sample task still work.

**4. Password-protect the demo**

Recommended, so the feedback form is not open to the whole internet.

1. Open **cPanel → Files → Directory Privacy**.
2. Open `betterschools.com.au`, then `gap-app`, then **Edit**.
3. Password-protect the directory, then create a username and a strong password.
4. In a private browser window, `https://betterschools.com.au/gap-app` should ask for that password before the page loads.

Deploy keeps the `cp:ppd` block. Do not replace the live `.htaccess` with a raw copy from Git.

**5. Later updates**

Merge into GitHub `main`. In cPanel, open **Git Version Control → Manage → Pull or Deploy**, click **Update from Remote**, then **Deploy HEAD Commit**. Do not move `config.php` again.

PHP 8.2 or newer is required, with `curl`, `gd`, `mbstring`, and `mail()`. If `Options -Indexes` causes a 500 error, ask the host to turn directory listing off, remove that one line from `public/.htaccess`, and deploy again.

## What not to commit

Do not commit `private/config.php`, API keys, school names, class codes, student names, or photos of student work. The sample task is a generic question with no student in it. The logo, Lexend font, and Marble files are the only third-party assets, and each keeps its own credit.
