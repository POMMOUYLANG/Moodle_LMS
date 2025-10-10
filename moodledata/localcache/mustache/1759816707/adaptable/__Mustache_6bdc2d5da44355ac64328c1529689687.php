<?php

class __Mustache_6bdc2d5da44355ac64328c1529689687 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<form action="';
        $value = $this->resolveValue($context->find('actionurl'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" method="post" id="adminsettings">
';
        $buffer .= $indent . '    <div>
';
        $buffer .= $indent . '        <input type="hidden" name="sesskey" value="';
        $value = $this->resolveValue($context->find('sesskey'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '        <input type="hidden" name="action" value="save-settings">
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '    <fieldset>
';
        $buffer .= $indent . '        <div class="clearer"></div>
';
        $buffer .= $indent . '        <h2 class="main">';
        $value = $context->find('str');
        $buffer .= $this->section814f7ab309abbd3b4cf598dd0e446d9a($context, $indent, $value);
        $buffer .= '</h2>
';
        $value = $context->find('hasresults');
        $buffer .= $this->section412049acc3caabc59c8b3baaf2b4fbfa($context, $indent, $value);
        $value = $context->find('hasresults');
        if (empty($value)) {
            
            $buffer .= $indent . '            ';
            $value = $context->find('str');
            $buffer .= $this->section36817e26d3b370aa587ec3ea3b300e7a($context, $indent, $value);
            $buffer .= '
';
        }
        $buffer .= $indent . '    </fieldset>
';
        $buffer .= $indent . '</form>
';

        return $buffer;
    }

    private function section814f7ab309abbd3b4cf598dd0e446d9a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'searchresults, admin';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'searchresults, admin';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionA092cd08353bf6cb94ecbf78ed80133b(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' pagepath, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' pagepath, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionFa704699da6784fe045758c930fc1de0(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                    <li class="small text-muted">{{.}}</li>
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                    <li class="small text-muted">';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '</li>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE0591e3c7143a8ce114dde7973b9e593(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                        <div class="clearer"></div>
                        {{{.}}}
                    ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                        <div class="clearer"></div>
';
                $buffer .= $indent . '                        ';
                $value = $this->resolveValue($context->last(), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionAa6633e122c13eae6d49d0de33184141(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <h3 class="adminpagetitle"><a href="{{url}}">{{{title}}}</a></h3>
                <ul class="adminpagepath" aria-label="{{#str}} pagepath, core {{/str}}">
                    {{#path}}
                    <li class="small text-muted">{{.}}</li>
                    {{/path}}
                </ul>
                <fieldset class="adminsettings">
                    {{#settings}}
                        <div class="clearer"></div>
                        {{{.}}}
                    {{/settings}}
                </fieldset>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <h3 class="adminpagetitle"><a href="';
                $value = $this->resolveValue($context->find('url'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">';
                $value = $this->resolveValue($context->find('title'), $context);
                $buffer .= ($value === null ? '' : $value);
                $buffer .= '</a></h3>
';
                $buffer .= $indent . '                <ul class="adminpagepath" aria-label="';
                $value = $context->find('str');
                $buffer .= $this->sectionA092cd08353bf6cb94ecbf78ed80133b($context, $indent, $value);
                $buffer .= '">
';
                $value = $context->find('path');
                $buffer .= $this->sectionFa704699da6784fe045758c930fc1de0($context, $indent, $value);
                $buffer .= $indent . '                </ul>
';
                $buffer .= $indent . '                <fieldset class="adminsettings">
';
                $value = $context->find('settings');
                $buffer .= $this->sectionE0591e3c7143a8ce114dde7973b9e593($context, $indent, $value);
                $buffer .= $indent . '                </fieldset>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionE5479b5825bee73d37f8a0a91fe85548(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'savechanges, admin';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'savechanges, admin';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section14a0ad8a44aff3e712dc65925f1ccfc9(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <div class="row">
                    <div class="offset-sm-3 col-sm-3">
                        <button type="submit" class="btn btn-primary">{{#str}}savechanges, admin{{/str}}</button>
                    </div>
                </div>
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <div class="row">
';
                $buffer .= $indent . '                    <div class="offset-sm-3 col-sm-3">
';
                $buffer .= $indent . '                        <button type="submit" class="btn btn-primary">';
                $value = $context->find('str');
                $buffer .= $this->sectionE5479b5825bee73d37f8a0a91fe85548($context, $indent, $value);
                $buffer .= '</button>
';
                $buffer .= $indent . '                    </div>
';
                $buffer .= $indent . '                </div>
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section412049acc3caabc59c8b3baaf2b4fbfa(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
            {{#results}}
                <h3 class="adminpagetitle"><a href="{{url}}">{{{title}}}</a></h3>
                <ul class="adminpagepath" aria-label="{{#str}} pagepath, core {{/str}}">
                    {{#path}}
                    <li class="small text-muted">{{.}}</li>
                    {{/path}}
                </ul>
                <fieldset class="adminsettings">
                    {{#settings}}
                        <div class="clearer"></div>
                        {{{.}}}
                    {{/settings}}
                </fieldset>
            {{/results}}
            {{#showsave}}
                <div class="row">
                    <div class="offset-sm-3 col-sm-3">
                        <button type="submit" class="btn btn-primary">{{#str}}savechanges, admin{{/str}}</button>
                    </div>
                </div>
            {{/showsave}}
        ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $value = $context->find('results');
                $buffer .= $this->sectionAa6633e122c13eae6d49d0de33184141($context, $indent, $value);
                $value = $context->find('showsave');
                $buffer .= $this->section14a0ad8a44aff3e712dc65925f1ccfc9($context, $indent, $value);
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section36817e26d3b370aa587ec3ea3b300e7a(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = 'noresults, admin';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= 'noresults, admin';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
