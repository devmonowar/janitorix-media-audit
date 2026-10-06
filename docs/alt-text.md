# Alt text — the missing-words list

Open the **Janitorix → Alt Text** screen. Every image in the media library is
one of four things: **Good**, **Missing**, **Weak** (present but useless, like
"IMG_2034" or a 200-character ramble), or **Decorative** (you said so).
The headline tells you the coverage: good plus decorative, over everything.

## Suggestions

A missing or weak image gets a suggestion built from what the site already
knows — filename first, then the title, then the parent post's title. The
source is written beside it ("Suggested from filename"), so you know what
you are reading.

The suggestion arrives **inside an editable box**. Apply it untouched, or
write your own words first — both save the same way, and both keep the
previous alt for **Undo** if it reads wrong afterwards.

## Decorative images

A decorative image is not a missing alt — it is a deliberate empty one.
**Mark decorative** records that decision, and the image counts as covered
from then on. **Not decorative** reverses it. Nothing is ever deleted here;
this screen only writes words.

## Optional AI suggestions

Rule-based suggestions need nothing and never go away. If you want a second
opinion from an AI model:

1. Open **Settings → AI suggestions**, enable it, and save **your own API
   key** and model name. The plugin ships no key and calls nothing until you do.
2. Press **Test connection** — it sends a tiny built-in sample image (none of
   yours) and reports whether the model described it.
3. Back on the Alt Text screen, each row has **Suggest with AI**. The answer
   lands in the same editable box, labelled "AI: \<model\>", for the same
   review-then-apply. **Dismiss** throws it away.

Each ask sends a resized copy of that one image (max 1024px) plus its
filename and parent post title to the service you configured — read that
service's terms first, and see the "External services" section in
`readme.txt`. Answers are cached per image and model, so asking twice bills
once; if the service is slow, down, or rate-limiting you, the button says so
and everything else keeps working.

## From the command line

`wp janitorix alt stats` prints the same coverage the Dashboard card shows.
Add `--format=json` for scripts and scheduled checks.
