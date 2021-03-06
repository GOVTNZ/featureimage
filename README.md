# GovtNZ Feature Image

## Installation

```
composer require govtnz\silverstripe-featureimage
```

## Purpose

FeatureImageExtension can be applied to a content page, and gives it behaviours
for a featured image. Key features are:

 *  CMS users can add multiple images per page, which correspond to the
    responsive breakpoints. Three images are supported, which correspond
    to the small, medium and large breakpoints.
 *  The module generates CSS files to import the images so that they
    can be included as background images, with no inline CSS.
 *  Breakpoints match those in Bootstrap 3.

# Usage

In the template, use ``<% include FeatureImage %>`` or define the feature image
div with class of 'feature-image'. It can contain any content; the background of
this container will use the correct image based on media selectors. The theme
needs to ensure that the display properties of this are set correctly for the
images being used.

In PageController::init, add:

````
if ($this->dataRecord->hasExtension('GovtNZ\SilverStripe\FeatureImage\Images')) {
   $this->dataRecord->requireFeaturedImageCSS();

   Requirements::css('govtnz/silverstripe-featureimage:css/featureimage.css');
}
````

This is required because while the extension adds behaviour to the page, the
controller is what adds in the requirements.

 *  Enable extra fields per class to allow text on top of the feature image,
    addition of a mobile sized feature image, and a screen-reader only description
    of the image (uses bootstrap sr-only class)

````
public function showFeatureImageText() {
   return true;
}

public function showFeatureImageMobile() {
   return true;
}

public function showFeatureImageAccessibleDescription() {
   return true;
}
````

# CMS Behaviour

Any pages that have FeatureImageExtension will also include a "Featured Images"
tab. This contains 3 fields for uploading an image. These correspond to the
small, medium and large responsive sizes.

On saving a page, the CSS file is regenerated from whatever images are present.
Note that if the image is not present for a given responsive size, it will be
excluded from the generated CSS file, and thus will not show at that size.

The CMS user is responsible for ensuring that the uploaded images have already
been set to the size required for each responsive size.

CMS users needs to be aware that changing images in draft will update live as
well; draft and live use the same featured images.

# Front-end behaviour

When a page is visited that has FeatureImageExtension present, it will include
the generated CSS file. It requires the snippet described above, as well as an
element with class="feature-element".

# Possible improvements

 *  Validate the image sizes that are uploaded for each of the 4 sizes.
 *  Scale the images if required.
 *  Adjust the image uploaders, as they may be too permissive.
 *  It would be better if the hacky controller code didn't need to be manually
    added, and somehow when the page is rendered this CSS is automatically
    added. Just find a hook that is invoked exactly once when a page is
    rendered.
