# Distributing Qualimetrix as a phar

The phar distribution stage landed in #107. `box.json`, the build command,
release attachment, package checks, and installation documentation now have
permanent owners outside this plan.

The remaining idea is to build the Docker image from the released phar. The
current `Dockerfile` installs Composer dependencies and copies the full source
tree into the final image. A Docker stage has not been written and is outside
the current campaign. When that work starts, measure the current image and
build context, specify how the image obtains the phar, and compare the image's
runtime behavior with the source-tree image, including parallel workers. The
earlier image-size measurements are historical, not a baseline for that work.
