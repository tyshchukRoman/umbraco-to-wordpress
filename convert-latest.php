<?php

class ConvertLatest
{
    public string $mediaDir = 'Media';
    public string $inputDir = 'umbraco_exports';
    public string $outputDir = 'wordpress_imports';

    public array $languageMap = [
        'en-gb' => 'uk',
        'da-dk' => 'dk', 
        'de-de' => 'de',
        'en-au' => 'au',
        'en-nz' => 'nz'
    ];

    public string $defaultLanguage = 'en-gb';

    public array $allFiles = [];
    public array $allMedia = [];

    public array $outputFiles = [];
    public array $categories = [];
    public array $images = [];

    public function __construct() {
        $this->loadCategories();
        $this->loadImages();
        $this->initializeFiles();
        $this->processBlogArticles();
    }


    /*
     * Load $categories array
     */
    public function loadCategories() 
    {
        $this->allFiles = glob("{$this->inputDir}/*");

        foreach ($this->allFiles as $file) {
            $xml = simplexml_load_file($file);

            if ($xml === false) continue;
            
            // Only process category content types
            if ((string)$xml->Info->ContentType !== 'category') continue;
        
            $categoryId = str_replace('-', '', (string)$xml['Key']);
            $categoryNames = [];
          
            foreach ($this->languageMap as $umbracoLang => $wpLang) {
                $name = $this->getValueForLanguage($xml->Info->NodeName->Name, $umbracoLang);

                if (!empty($name)) {
                    $categoryNames[$umbracoLang] = $name;
                }
            }
            
            if (!empty($categoryNames)) {
                $this->categories[$categoryId] = $categoryNames;
            }
        }
    }


    /*
     * Load $images array
     */
    public function loadImages() 
    {
        $this->allMedia = glob("{$this->mediaDir}/*");

        foreach ($this->allMedia as $file) {
            $xml = simplexml_load_file($file);

            if ($xml === false) continue;
            
            // Only process Image content types
            if ((string)$xml->Info->ContentType !== 'Image') continue;
        
            $imageId = str_replace('-', '', (string)$xml['Key']);
            $imageSrcArray = explode('/', (string)$xml->Properties->umbracoFile->Value);

            $imageName = array_pop($imageSrcArray);
            $imageSrc = 'https://smartparking.codelibry.dev/wp-content/uploads/2025/04/' . $imageName;
            $imageSrc = str_replace('"}', '', $imageSrc);

            $this->images[$imageId] = $imageSrc;
        }
    }


    /*
     * Initialize Files
     */
    public function initializeFiles() 
    {
        foreach ($this->languageMap as $umbracoLang => $wpLang) {
            $filename = "latest-{$wpLang}.xml";
            $outputPath = "{$this->outputDir}/{$filename}";
            
            $fileHandle = fopen($outputPath, 'w');
            if ($fileHandle === false) {
                die("Error creating output file: {$outputPath}\n");
            }
            
            $this->outputFiles[$umbracoLang] = $fileHandle;
            fwrite($fileHandle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
            fwrite($fileHandle, '<wpml>' . PHP_EOL);
            fwrite($fileHandle, '  <posts>' . PHP_EOL);
        }
    }


    /*
     * Process Files
     */
    public function processBlogArticles() 
    {
        // Track processed IDs to avoid duplicates
        $processedIds = [];
        $processedCount = 0;

        // Second pass: Process blog articles
        foreach ($this->allFiles as $file) {
            $xml = simplexml_load_file($file);
            if ($xml === false) continue;

            // Only process blogArticle content types
            if ((string)$xml->Info->ContentType !== 'blogArticle') continue;

            $postId = (string)$xml['Key'];
            if (isset($processedIds[$postId])) continue;
            $processedIds[$postId] = true;

            // Skip if not published
            if ((string)$xml->Info->Published->Published != 'true') continue;

            // Get category references
            $categoryRefs = [];
            $categoryValue = (string)$xml->Properties->category->Value;
            if (!empty($categoryValue)) {
                $refs = explode(',', $categoryValue);
                foreach ($refs as $ref) {
                    $ref = trim($ref);
                    if (!empty($ref)) {
                        $categoryRefs[] = $ref;
                    }
                }
            }

            // Process for each language
            foreach ($this->languageMap as $umbracoLang => $wpLang) {
                $output = $this->outputFiles[$umbracoLang];
                
                // Get language-specific content
                $title = $this->getValueForLanguage($xml->Info->NodeName->Name, $umbracoLang);
                $content = $this->getValueForLanguage($xml->Properties->bodyText->Value, $umbracoLang);
                
                if (empty($title) || empty($content)) continue;

                // Prepare post data
                $metaTitle = $this->getValueForLanguage($xml->Properties->metaTitle->Value, $umbracoLang) ?: $title;
                $metaDesc = $this->getValueForLanguage($xml->Properties->metaDescription->Value, $umbracoLang);
                $createDate = (string)$xml->Info->CreateDate;
                $testimonialJson = $this->getValueForLanguage($xml->Properties->testimonial->Value, $umbracoLang);
                $testimonialData = !empty($testimonialJson) ? json_decode($testimonialJson, true) : null;
                $featuredImageId = str_replace('-', '', $this->getMediaKey($this->getValueForLanguage($xml->Properties->featuredImage->Value, $umbracoLang)));

                // Write post
                fwrite($output, '    <post>' . PHP_EOL);
                fwrite($output, '      <id>' . htmlspecialchars($postId, ENT_QUOTES, 'UTF-8') . '</id>' . PHP_EOL);
                fwrite($output, '      <title><![CDATA[' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ']]></title>' . PHP_EOL);
                fwrite($output, '      <post_type>post</post_type>' . PHP_EOL);
                fwrite($output, '      <post_status>publish</post_status>' . PHP_EOL);
                fwrite($output, '      <post_date>' . $createDate . '</post_date>' . PHP_EOL);
                fwrite($output, '      <post_content><![CDATA[' . $content . ']]></post_content>' . PHP_EOL);
                
                // Featured image
                if (isset($this->images[$featuredImageId])) {
                    fwrite($output, '      <featured_image>' . $this->images[$featuredImageId] . '</featured_image>' . PHP_EOL);
                }
                
                // Categories
                if (!empty($categoryRefs)) {
                    fwrite($output, '      <categories>');
                    foreach ($categoryRefs as $i => $ref) {
                        $categoryId = $this->extractContentKey($ref);

                        if (isset($this->categories[$categoryId][$umbracoLang])) {
                            $i === 0 
                                ? fwrite($output, htmlspecialchars($this->categories[$categoryId][$umbracoLang], ENT_QUOTES, 'UTF-8'))
                                : fwrite($output, ',' . htmlspecialchars($this->categories[$categoryId][$umbracoLang], ENT_QUOTES, 'UTF-8'));
                        }
                    }
                    fwrite($output, '</categories>' . PHP_EOL);
                }
                
                // Testimonial ACF fields
                if ($testimonialData && isset($testimonialData[0])) {
                    $testimonial = $testimonialData[0];
                    fwrite($output, '      <acf>' . PHP_EOL);
                    fwrite($output, '        <testimonial>' . PHP_EOL);
                    fwrite($output, '          <name><![CDATA[' . htmlspecialchars($testimonial['name'] ?? '', ENT_QUOTES, 'UTF-8') . ']]></name>' . PHP_EOL);
                    fwrite($output, '          <position><![CDATA[' . htmlspecialchars($testimonial['position'] ?? '', ENT_QUOTES, 'UTF-8') . ']]></position>' . PHP_EOL);
                    fwrite($output, '          <company><![CDATA[' . htmlspecialchars($testimonial['company'] ?? '', ENT_QUOTES, 'UTF-8') . ']]></company>' . PHP_EOL);
                    fwrite($output, '          <quote><![CDATA[' . htmlspecialchars($testimonial['quote'] ?? '', ENT_QUOTES, 'UTF-8') . ']]></quote>' . PHP_EOL);
                    fwrite($output, '        </testimonial>' . PHP_EOL);
                    fwrite($output, '      </acf>' . PHP_EOL);
                }
                
                // SEO meta fields
                fwrite($output, '      <meta>' . PHP_EOL);
                fwrite($output, '        <_yoast_wpseo_title><![CDATA[' . htmlspecialchars($metaTitle, ENT_QUOTES, 'UTF-8') . ']]></_yoast_wpseo_title>' . PHP_EOL);
                if (!empty($metaDesc)) {
                    fwrite($output, '        <_yoast_wpseo_metadesc><![CDATA[' . htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') . ']]></_yoast_wpseo_metadesc>' . PHP_EOL);
                }
                fwrite($output, '        <_yoast_wpseo_focuskw><![CDATA[' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ']]></_yoast_wpseo_focuskw>' . PHP_EOL);
                fwrite($output, '      </meta>' . PHP_EOL);
                fwrite($output, '    </post>' . PHP_EOL);
                
                $processedCount++;
            }
        }

        // Close files
        foreach ($this->outputFiles as $fileHandle) {
            fwrite($fileHandle, '  </posts>' . PHP_EOL);
            fwrite($fileHandle, '</wpml>' . PHP_EOL);
            fclose($fileHandle);
        }

        echo "Conversion complete. Processed {$processedCount} posts.\n";
    }


    /*
     * Helper Functions
     */
    public function getValueForLanguage($node, $targetLanguage) {
        if ($node === null) return '';
        
        // If it's a simple value without language variations
        if (!isset($node['Culture'])) {
            return (string)$node;
        }
        
        // Look for exact language match
        foreach ($node as $value) {
            if ((string)$value['Culture'] === $targetLanguage) {
                return (string)$value;
            }
        }
        
        // If no match found, return empty string
        return '';
    }

    public function getMediaKey($jsonString) {
        if (empty($jsonString)) return '';
        $data = json_decode($jsonString, true);
        return $data && isset($data[0]['mediaKey']) ? $data[0]['mediaKey'] : '';
    }

    public function extractContentKey($umbracoRef) {
        if (preg_match('/umb:\/\/document\/([a-f0-9-]+)/i', $umbracoRef, $matches)) {
            return $matches[1];
        }
        return $umbracoRef;
    }
}

$converter = new ConvertLatest();
