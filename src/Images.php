<?php

namespace GovtNZ\SilverStripe\FeatureImage;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\File;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Folder;
use SilverStripe\View\Requirements;
use SilverStripe\Core\Injector\Injector;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * FeatureImageExtension is a an extension that can be applied to a content
 * page type to give it a set of behaviours for handling feature images:
 *
 *    * a "Featured Images" tab in the CMS
 *    * 3 image uploader fields. These let a CMS user upload 3 variations of the feature image,
 *      corresponding to the 3 responsive breakpoints.
 *
 * See README.md for more info on usage.
 */
class Images extends DataExtension
{
    protected $imagesEnabled = true;

    private static $db = [
        'FeatureText' => 'Text',
        'FeaturedImageText' => 'Text'
    ];

    private static $has_one = [
        'FeatureImageMobile' => Image::class,
        'FeatureImageSmall' => Image::class,
        'FeatureImageMedium' => Image::class,
        'FeatureImageLarge' => Image::class
    ];

    private static $owns = [
        'FeatureImageMobile',
        'FeatureImageSmall',
        'FeatureImageMedium',
        'FeatureImageLarge'
    ];

    /**
     * mapping of bootstrap media sizes to the relation names that contain the
     * images. These are the default sizes. We go from larger to smaller, so
     * that if a smaller image is missing, we can use the next larger one.
     */
    protected $responsiveData = [
        '1367px' => 'FeatureImageLarge',        // corresponds to screen-lg-min
        '992px' => 'FeatureImageMedium',        // corresponds to screen-md-min
        '768px' => 'FeatureImageSmall',            // corresponds to screen-sm-min
        '1px' => 'FeatureImageMobile'
    ];

    private static $feature_images_root = "feature-images/";

    private static $css_include_name = "include.css";

    private $_cachedFolderPath;

    /**
     * @config
     */
    private static $enable_cms_fields = true;

    /**
     * Add the 3 upload fields to a "Featured Images" tab in the CMS, for pages
     * that have this extension.
     */
    public function updateCMSFields(FieldList $fields)
    {
        if ($this->imagesEnabled === false) {
            return;
        }

        if ($this->owner->hasMethod('shouldEnableFeatureImages')) {
            if (!$this->owner->shouldEnableFeatureImages()) {
                return;
            }
        }

        if ($this->owner->hasMethod('showFeatureImageAccessibleDescription')) {
            if ($this->owner->showFeatureImageAccessibleDescription()) {
                $fields->addFieldToTab('Root.FeatureImages', new TextField('FeaturedImageText', 'Describe the text on the featured image (if present)'));
            }
        }

        if ($this->owner->hasMethod('showFeatureImageMobile')) {
            if ($this->owner->showFeatureImageMobile()) {
                $fields->addFieldToTab(
                    'Root.FeatureImages',
                    $this->getUploadField('FeatureImageMobile', 'Mobile')->setDescription('767 x 210 px')
                );
            }
        }
        $fields->addFieldToTab(
            'Root.FeatureImages',
            $this->getUploadField('FeatureImageSmall', 'Small')->setDescription('991 x 180 px')
        );

        $fields->addFieldToTab(
            'Root.FeatureImages',
            $this->getUploadField('FeatureImageMedium', 'Medium')->setDescription('1366 x 180 px')
        );

        $fields->addFieldToTab(
            'Root.FeatureImages',
            $this->getUploadField('FeatureImageLarge', 'Large')->setDescription('1920 x 250 px')
        );

        if ($this->owner->hasMethod('showFeatureImageText')) {
            if ($this->owner->showFeatureImageText()) {
                $fields->addFieldToTab('Root.FeatureImages', new TextareaField('FeatureText', 'Feature text'));
            }
        }
    }

    /**
     * Helper to create an upload field with the correct path, which ensures
     * that the uploader puts files in the right place.
     */
    protected function getUploadField($fieldName, $caption)
    {
        $field = UploadField::create("{$fieldName}", $caption);
        $field->setFolderName(Controller::join_links(
            self::$feature_images_root,
            $this->owner->ID . "_" . $this->owner->URLSegment
        ));

        return $field;
    }

    /**
     * Return the path we should use for feature images and related CSS for
     * this page. It works as follows:
     * - if a folder exists under feature_images_root whose name starts with
     * nnn_ where nnn is the ID of the page,
     *     that path is returned.
     * - otherwise that folder path is created. The name of the folder will be
     * nnn_tttt where nnn is the ID of the page
     *   and tttt is the URL segment, stripped of punctuation.
     * The folder path should have a trailing slash.
     */
    protected function getFolderPath()
    {
        if (!$this->_cachedFolderPath) {
            $this->_cachedFolderPath = $this->createFolder();
        }

        return $this->_cachedFolderPath;
    }

    /**
     * Return the feature folder URL (rather than OS path)
     */
    protected function getFeatureFolderURL()
    {
        return Controller::join_links(
            'assets',
            self::$feature_images_root,
            $this->owner->ID . "_" . $this->owner->URLSegment
        );
    }

    /**
     * Create the folder structure for this page, and return the path.
     */
    protected function createFolder()
    {
        $root = Controller::join_links(
            ASSETS_PATH,
            self::$feature_images_root
        );

        @mkdir($root);

        $path = Controller::join_links(
            ASSETS_PATH,
            self::$feature_images_root,
            $this->owner->ID . "_" . $this->owner->URLSegment
        );

        @mkdir($path);

        return $path;
    }

    /**
     * After a write, we need to ensure that the images are in the right
     * folder. This is required because UploadField ignores the path to save to,
     * and just puts the files in Uploads. So we iterate over the images we
     * have, move them to the right place if they are in the wrong place, and
     * then generate a CSS file that can be included.
     */
    public function onAfterWrite()
    {
        if ($this->hasFeatureImages()) {
            $this->regenerateCSSFile();
        }
    }

    /**
     * Generate a CSS file with the correct rules that references the images
     * that have been uploaded. The CSS file is stored in the correct folder
     * for this page.
     */
    protected function regenerateCSSFile()
    {
        $css = $this->getCSS();
        $path = $this->getFeatureCSSPath();

        $fh = fopen($path, "w");
        fwrite($fh, $css);
        fclose($fh);
    }

    public function featureImagesCSSExists()
    {
        return file_exists($this->getFeatureCSSPath());
    }

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

        // We've added them in the reverse order they need to appear in the CSS
        // file, so we could process large->small, so do a final reverse of the
        // clauses.
        $clauses = array_reverse($clauses);

        return implode("\n", $clauses);
    }

    /**
     * Return the path of the CSS file for this page. This will either be
     * because we are generating the file, or because we are requiring it's
     * path when rendering the page on the front end.
     */
    public function getFeatureCSSPath()
    {
        return Controller::join_links(
            $this->getFolderPath(),
            self::$css_include_name
        );
    }

    public function getFeatureCSSURL()
    {
        return Controller::join_links(
            $this->getFeatureFolderURL(),
            self::$css_include_name
        );
    }

    /**
     * Use Requirements system to pull in the CSS file for this page. This
     * should be invoked whenever a page that has this extension is being
     * rendered. Typical usage is to put it in init() of the page types
     * controller class.
     */
    public function requireFeaturedImageCSS()
    {
        if ($this->hasFeatureImages()) {
            // just use requirements to pull in the CSS.
            if (!$this->featureImagesCSSExists()) {
                try {
                    $this->regenerateCSSFile();
                } catch (Exception $e) {
                    Injector::inst()->get(LoggerInterface::class)->warning($e->getMessage());
                }
            }

            if ($this->featureImagesCSSExists()) {
                Requirements::css($this->getFeatureCSSURL());
            }
        }
    }

    /**
     * Return true if the extended page has any featured images, false if not.
     * Even if there is only one featured image, it will return true, as the it
     * may fall back to a bigger image.
     *
     * @return boolean
     */
    public function hasFeatureImages()
    {
        foreach ($this->responsiveData as $size => $relName) {
            if (!$this->owner->hasMethod($relName)) {
                continue;
            }

            $file = $this->owner->$relName();

            if ($file && $file->ID) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param boolean $val
     *
     * @return
     */
    public function setFeaturedImagesEnabled($val)
    {
        $this->imagesEnabled = $val;

        return $this->owner;
    }
}
