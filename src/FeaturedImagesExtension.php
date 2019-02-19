<?php

namespace GovtNZ\SilverStripe\FeatureImage;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;

/**
 * FeatureImageExtension is a an extension that can be applied to a content page type to give it
 * a set of behaviours for handling feature images:
 *
 *    * a "Featured Images" tab in the CMS
 *    * 3 image uploader fields. These let a CMS user upload 3 variations of the feature image,
 *      corresponding to the 3 responsive breakpoints.
 *
 * See README.md for more info on usage.
 */
class FeaturedImagesExtension extends DataExtension
{

    // Folder that will contain sub-folders for different pages. Relative to assets directory.
    static $feature_images_root = "feature-images/";

    // File name given to generated CSS files, within each featured image folder.
    static $css_include_name = "include.css";

    var $_cachedFolderPath;

    private static $db = array(
        'FeatureText' => 'Text',
        'FeaturedImageText' => 'Text'
    );

    /**
     * @config
     */
    private static $enable_cms_fields = true;

    private static $has_one = array(
        'FeatureImageMobile' => 'Image',
        'FeatureImageSmall' => 'Image',
        'FeatureImageMedium' => 'Image',
        'FeatureImageLarge' => 'Image'
    );

    /**
     * Add the 3 upload fields to a "Featured Images" tab in the CMS, for pages that have this extension.
     */
    public function updateCMSFields(FieldList $fields)
    {
        if (self::$enable_cms_fields === false) {
            return;
        }

        if ($this->owner->hasMethod('showFeatureImageAccessibleDescription')) {
            if ($this->owner->showFeatureImageAccessibleDescription()) {
                $fields->addFieldToTab('Root.FeatureImages', new TextField('FeaturedImageText', 'Describe the text on the featured image (if present)'));
            }
        }

        if ($this->owner->hasMethod('showFeatureImageMobile')) {
            if ($this->owner->showFeatureImageMobile()) {
                $fields->addFieldToTab('Root.FeatureImages', $this->getUploadField('FeatureImageMobile', 'Mobile (<em>767 x 210 px</em>)'));
            }
        }
        $fields->addFieldToTab('Root.FeatureImages', $this->getUploadField('FeatureImageSmall', 'Small (<em>991 x 180 px</em>)'));
        $fields->addFieldToTab('Root.FeatureImages', $this->getUploadField('FeatureImageMedium', 'Medium (<em>1366 x 180 px</em>)'));
        $fields->addFieldToTab('Root.FeatureImages', $this->getUploadField('FeatureImageLarge', 'Large (<em>1920 x 250 px</em>)'));

        if ($this->owner->hasMethod('showFeatureImageText')) {
            if ($this->owner->showFeatureImageText()) {
                $fields->addFieldToTab('Root.FeatureImages', new TextareaField('FeatureText', 'Feature text'));
            }
        }
    }

    /**
     * Helper to create an upload field with the correct path, which ensures that the uploader puts files in the right place
     */
    protected function getUploadField($field, $caption)
    {
        $field = new UploadField($field, $caption);
        $path = $this->getFolderPath();
        $field->setFolderName($path);
        return $field;
    }

    /**
     * Return the path we should use for feature images and related CSS for this page. It works as follows:
     * - if a folder exists under feature_images_root whose name starts with nnn_ where nnn is the ID of the page,
     *     that path is returned.
     * - otherwise that folder path is created. The name of the folder will be nnn_tttt where nnn is the ID of the page
     *   and tttt is the URL segment, stripped of punctuation.
     * The folder path should have a trailing slash.
     */
    protected function getFolderPath()
    {
        if (!$this->_cachedFolderPath) {
            $f = $this->findExisting();
            if ($f) {
                $this->_cachedFolderPath = $f;
            } else {
                $this->_cachedFolderPath = $this->createFolder();
            }

            if (substr($this->_cachedFolderPath, -1) != "/") {
                $this->_cachedFolderPath .= "/";
            }
        }
        return $this->_cachedFolderPath;
    }

    /**
     * Determine if there is a folder for this page's ID already. If so, return its path relative to
     * assets. If not, return null.
     */
    protected function findExisting()
    {
        $base = Folder::find_or_make(self::$feature_images_root);
        foreach ($base->Children() as $child) {
            $parts = explode("-", $child->Name);
            if ($parts[0] == $this->owner->ID) {
                // return this path, but strip leading "assets/"
                $result = $child->Filename;
                if (substr($result, 0, 7) == "assets/") {
                    $result = substr($result, 7);
                }
                return $result;
            }
        }
        return null;
    }

    /**
     * Create the folder structure for this page, and return the path.
     */
    protected function createFolder()
    {
        $path = self::$feature_images_root . $this->owner->ID . "_" . $this->owner->URLSegment;
        // @todo is any sanitation required? exception handling?
        Folder::find_or_make($path);
        return $path;
    }

    /**
     * After a write, we need to ensure that the images are in the right folder. This is required because UploadField
     * ignores the path to save to, and just puts the files in Uploads. So we iterate over the images we have, move them
     * to the right place if they are in the wrong place, and then generate a CSS file that can be included.
     */
    public function onAfterWrite()
    {
        // @todo(mark) determine if the fields have changed; only generate css if not present or fields have changed. hash contents.
        if ($this->hasFeatureImages()) {
            $this->regenerateCSSFile();
        }
    }

    /**
     * Generate a CSS file with the correct rules that references the images that have been uploaded. The CSS file is
     * stored in the correct folder for this page.
     */
    protected function regenerateCSSFile()
    {
        $css = $this->getCSS();

        $folder = Folder::find_or_make($this->getFolderPath());

        // Create the file, with the CSS content in it.
        $path = 'assets/' . $this->getCSSPath();
        $fh = fopen(BASE_PATH . '/' . $path, "w");
        fwrite($fh, $css);
        fclose($fh);

        // Create the File reference that points to the physical file.
        $file = new File();
        $file->Name = self::$css_include_name;
        $file->Filename = $path;

        // Make sure the new File has the correct parent folder.
        $file->ParentID = $folder->ID;
        $file->write();
    }

    // mapping of bootstrap media sizes to the relation names that contain the images. These are the default
    // sizes. We go from larger to smaller, so that if a smaller image is missing, we can use the next larger one.
    protected $responsiveData = array(
        '1367px' => 'FeatureImageLarge',        // corresponds to screen-lg-min
        '992px' => 'FeatureImageMedium',        // corresponds to screen-md-min
        '768px' => 'FeatureImageSmall',            // corresponds to screen-sm-min
        '1px' => 'FeatureImageMobile'
    );

    /**
     * Generate the CSS content.
     */
    protected function getCSS()
    {
        $css = "";

        $clauses = array();

        // For each image relation we actually have, emit the appropriate rule
        $previousFile = null;
        $urlLargest = null;
        foreach ($this->responsiveData as $responsiveSize => $relName) {
            $file = $this->owner->$relName();

            if (($file && $file->ID) || $previousFile) {
                if ($file && $file->ID) {
                    // Use the file URL if the file is present for this size.
                    // Implementation note: use of relative URLs has been discontinued as it doesn't work for images
                    // that are linked from other parts of files and images. Using getAbsoluteURL works for all cases
                    // including renaming, but doesn't work if the site is not running as a virtual host.
                    $url = $file->getAbsoluteURL();
                    // $url = "./" . $file->Name;
                } elseif ($previousFile) {
                    // Use the previous file if there is no file, which means using the image from a larger
                    // responsive breakpoint. This relies on the fact that the image heights are all the
                    // same, and the images are centered.
                    $url = $previousFile->getAbsoluteURL();
                    // $url = "./" . $previousFile->Name;
                } else {
                    $url = '';
                }

                 // remove the protocol and host from the URL. This means the path will be absolute relative to
                 // web root, and will still work in dev environments.
                 $host = Director::protocolAndHost();
                if (substr($url, 0, strlen($host)) == $host) {
                    $url = substr($url, strlen($host));
                }

                // Remember the url of the largest image we find. This is what we'll use for IE8
                if (!$urlLargest) {
                    $urlLargest = $url;
                }

                $css = "@media (min-width: $responsiveSize) {\n";
                $css .= "\t.feature-image {\n";
                $css .= "\t\tbackground: url(" . $url . ") #231f20 no-repeat;\n";
                // $css .= "\t\tbackground-size: cover;\n";
                $css .= "\t\tbackground-position:center;";
                $css .= "\t}\n";
                $css .= "}\n\n";

                $clauses[] = $css;

                $previousFile = $file;
            }
        }
        if ($this->owner->FeatureImageMobileID > 0) {
            // Media rule is to show the feature image on mobile only if there is one.
            $css = "@media (max-width: 767px) {\n";
            $css .= "\t.feature-image {\n";
            $css .= "\t\tdisplay: block!important; margin-bottom: 0!important;\n";
            $css .= "\t}\n";
            $css .= "\t.feature-image .row{margin-top: 188px!important;}\n";
            $css .= "}\n\n";
        } else {
            // Media rule is to hide the feature image completely on mobile. Other sizes will override.
            $css = "@media (max-width: 767px) {\n";
            $css .= "\t.feature-image {\n";
            $css .= "\t\tdisplay: none;\n";
            $css .= "\t\tvisibility: hidden;\n";
            $css .= "\t}\n";
            $css .= "}\n\n";
        }
        $clauses[] = $css;

        // We've added them in the reverse order they need to appear in the CSS file, so we could process large->small,
        // so do a final reverse of the clauses.
        $clauses = array_reverse($clauses);

        return implode("\n", $clauses);
    }

    /**
     * Return the path of the CSS file for this page. This will either be because we are generating the file, or because
     * we are requiring it's path when rendering the page on the front end.
     */
    protected function getCSSPath()
    {
        return $this->getFolderPath() . self::$css_include_name;
    }

    /**
     * Use Requirements system to pull in the CSS file for this page. This should be invoked whenever a page that
     * has this extension is being rendered. Typical usage is to put it in init() of the page types controller class.
     */
    public function requireFeaturedImageCSS()
    {
        if ($this->hasFeatureImages()) {
            // just use requirements to pull in the CSS.
            Requirements::css('assets/' . $this->getCSSPath());
        }
    }

    /**
     * Return true if the extended page has any featured images, false if not. Even if there is only
     * one featured image, it will return true, as the it may fall back to a bigger image.
     */
    public function hasFeatureImages()
    {
        foreach ($this->responsiveData as $size => $relName) {
            $file = $this->owner->$relName();
            if ($file && $file->ID) {
                return true; // found one
            }
        }
        return false;
    }

    public static function set_enable_cms_fields($val)
    {
        self::$enable_cms_fields = $val;
    }
}
