<?php

class __Mustache_bda1bb846113d8af269efb64a6f7284e extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $value = $this->resolveValue($context->findDot('output.doctype'), $context);
        $buffer .= $indent . ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '<html ';
        $value = $this->resolveValue($context->findDot('output.htmlattributes'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '>
';
        $buffer .= $indent . '<head>
';
        $buffer .= $indent . '    <title>';
        $value = $this->resolveValue($context->findDot('output.page_title'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</title>
';
        $buffer .= $indent . '    <link rel="shortcut icon" href="';
        $value = $this->resolveValue($context->findDot('output.favicon'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '" />
';
        $buffer .= $indent . '    ';
        $value = $this->resolveValue($context->findDot('output.standard_head_html'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '
';
        $buffer .= $indent . '    <meta name="viewport" content="width=device-width, initial-scale=1.0">
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '    <!-- Twitter Card data -->
';
        $buffer .= $indent . '    <meta name="twitter:card" value="summary">
';
        $buffer .= $indent . '    <meta name="twitter:site" value="';
        $value = $this->resolveValue($context->find('sitefullname'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    <meta name="twitter:title" value="';
        $value = $this->resolveValue($context->find('pagetitle'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '    <!-- Open Graph data -->
';
        $buffer .= $indent . '    <meta property="og:title" content="';
        $value = $this->resolveValue($context->find('pagetitle'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    <meta property="og:type" content="website" />
';
        $buffer .= $indent . '    <meta property="og:url" content="';
        $value = $this->resolveValue($context->find('siteurl'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '    <meta name="og:site_name" value="';
        $value = $this->resolveValue($context->find('sitefullname'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '    <!-- Chrome, Firefox OS and Opera on Android topbar color -->
';
        $buffer .= $indent . '    <meta name="theme-color" content="';
        $value = $this->resolveValue($context->find('maincolour'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '    <!-- Windows Phone topbar color -->
';
        $buffer .= $indent . '    <meta name="msapplication-navbutton-color" content="';
        $value = $this->resolveValue($context->find('maincolour'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '
';
        $buffer .= $indent . '    <!-- iOS Safari topbar color -->
';
        $buffer .= $indent . '    <meta name="apple-mobile-web-app-status-bar-style" content="';
        $value = $this->resolveValue($context->find('maincolour'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '
';
        $value = $context->find('fontname');
        $buffer .= $this->section87a7e860175c19ab93988b327a3817f4($context, $indent, $value);
        $value = $context->find('fontheadername');
        $buffer .= $this->sectionD18a961e64f3480afd164e1b7a2474c2($context, $indent, $value);
        $value = $context->find('fonttitlename');
        $buffer .= $this->section9d7f609abfc70a425172965c2a70a8f1($context, $indent, $value);
        $buffer .= $indent . '</head>';

        return $buffer;
    }

    private function section87a7e860175c19ab93988b327a3817f4(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <!-- Load Google main font -->
    <link href="https://fonts.googleapis.com/css?family={{fontname}}" rel="stylesheet" type="text/css">
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <!-- Load Google main font -->
';
                $buffer .= $indent . '    <link href="https://fonts.googleapis.com/css?family=';
                $value = $this->resolveValue($context->find('fontname'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" rel="stylesheet" type="text/css">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionD18a961e64f3480afd164e1b7a2474c2(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <!-- Load Google header font -->
    <link href="https://fonts.googleapis.com/css?family={{fontheadername}}" rel="stylesheet" type="text/css">
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <!-- Load Google header font -->
';
                $buffer .= $indent . '    <link href="https://fonts.googleapis.com/css?family=';
                $value = $this->resolveValue($context->find('fontheadername'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" rel="stylesheet" type="text/css">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section9d7f609abfc70a425172965c2a70a8f1(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
    <!-- Load Google title font -->
    <link href="https://fonts.googleapis.com/css?family={{fonttitlename}}" rel="stylesheet" type="text/css">
    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '    <!-- Load Google title font -->
';
                $buffer .= $indent . '    <link href="https://fonts.googleapis.com/css?family=';
                $value = $this->resolveValue($context->find('fonttitlename'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" rel="stylesheet" type="text/css">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
