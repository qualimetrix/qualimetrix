# Distributing Qualimetrix as a phar

## The problem, in one sentence

The tool ships as a composer package of 1196 files and a Docker image that copies the whole source
tree, and `website/docs/getting-started/installation.md` has been promising a phar as "Coming soon"
to anyone who reads it.

## The decision

Build a phar and attach it to the release. Then, separately, rebuild the Docker image on that phar
instead of on a copy of the tree. **Two stages, two pull requests, in that order** — the owner's
stated order, and stage 02 has nothing to build on until stage 01 ships an artifact.

## Stages

| Stage            | Subject                                                                     | State                             |
| ---------------- | --------------------------------------------------------------------------- | --------------------------------- |
| [01](01-phar.md) | the phar itself: tooling, entry point, release attachment, the docs promise | planned                           |
| 02               | the Docker image rebuilt on the phar                                        | planned when 01 lands, not before |

Stage 02 is deliberately not written yet. Its whole content is a comparison against what stage 01
produces — image size, what the context carries, what the `docker-image` job exercises — and a plan
written before that artifact exists would be a plan about a guess.

## What this campaign inherits

Today's Docker baseline, to be compared against in stage 02 and stated here so stage 02 does not
re-derive it from a tree that has moved: a 399 MB final image from a 141.69 MB build context,
measured on `423df74a`. Stage 02 re-takes both.

The `docker-image` CI job smoke-tests the image with `--workers=0`, so it exercises no parallel
worker at all. That is a coverage hole today, independent of the phar, and stage 01 inherits it as a
reason its own evidence must not repeat the same mistake.
