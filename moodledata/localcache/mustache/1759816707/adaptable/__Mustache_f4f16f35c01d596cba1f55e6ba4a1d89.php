<?php

class __Mustache_f4f16f35c01d596cba1f55e6ba4a1d89 extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        $buffer .= $indent . '<div id="searchinput-navbar-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '" class="simplesearchform adsimplesearchform">
';
        $buffer .= $indent . '    <div class="collapse" id="searchform-navbar-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '        <form autocomplete="off" action="';
        $value = $this->resolveValue($context->find('action'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '" method="get" accept-charset="utf-8" class="mform d-flex flex-wrap align-items-center searchform-navbar">
';
        $value = $context->find('hiddenfields');
        $buffer .= $this->section04b8ae4b53b0a507223620372a841e3e($context, $indent, $value);
        $buffer .= $indent . '            <div class="input-group">
';
        $buffer .= $indent . '                <input type="text"
';
        $buffer .= $indent . '                    id="searchinput-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    class="form-control withclear"
';
        $buffer .= $indent . '                    placeholder="';
        $value = $this->resolveValue($context->find('searchstring'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '"
';
        $buffer .= $indent . '                    aria-label="';
        $value = $this->resolveValue($context->find('searchstring'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '"
';
        $buffer .= $indent . '                    name="';
        $value = $this->resolveValue($context->find('inputname'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '"
';
        $buffer .= $indent . '                    data-region="input"
';
        $buffer .= $indent . '                    autocomplete="off"
';
        $buffer .= $indent . '                >
';
        $buffer .= $indent . '                <label for="searchinput-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '">
';
        $buffer .= $indent . '                    <span class="visually-hidden">';
        $value = $this->resolveValue($context->find('searchstring'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</span>
';
        $buffer .= $indent . '                </label>
';
        $buffer .= $indent . '                <button class="btn btn-close adsimplesearchclose"
';
        $buffer .= $indent . '                    data-action="closesearch"
';
        $buffer .= $indent . '                    data-bs-toggle="collapse"
';
        $buffer .= $indent . '                    data-bs-target="#searchform-navbar-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '                    type="button"
';
        $buffer .= $indent . '                >
';
        $buffer .= $indent . '                    ';
        $value = $context->find('pix');
        $buffer .= $this->section01a1cb688132d57fc41f0070a1ef998d($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '                    <span class="visually-hidden">';
        $value = $context->find('str');
        $buffer .= $this->section256776dc607cdebe9d890b0efd29b5ba($context, $indent, $value);
        $buffer .= '</span>
';
        $buffer .= $indent . '                </button>
';
        $buffer .= $indent . '                <button type="submit" class="btn btn-submit" data-action="submit">
';
        $buffer .= $indent . '                    ';
        $value = $context->find('pix');
        $buffer .= $this->section31a49b2ab335cdb4725ddb42ed8de96c($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '                    <span class="visually-hidden">';
        $value = $this->resolveValue($context->find('searchstring'), $context);
        $buffer .= ($value === null ? '' : $value);
        $buffer .= '</span>
';
        $buffer .= $indent . '                </button>
';
        $buffer .= $indent . '            </div>
';
        $buffer .= $indent . '        </form>
';
        $buffer .= $indent . '    </div>
';
        $buffer .= $indent . '    <button
';
        $buffer .= $indent . '        class="rounded-0 nav-link icon-no-margin"
';
        $buffer .= $indent . '        data-bs-toggle="collapse"
';
        $buffer .= $indent . '        data-bs-target="#searchform-navbar-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        data-action="opensearch"
';
        $buffer .= $indent . '        type="button"
';
        $buffer .= $indent . '        aria-expanded="false"
';
        $buffer .= $indent . '        aria-controls="searchform-navbar-';
        $value = $this->resolveValue($context->find('uniqid'), $context);
        $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
        $buffer .= '"
';
        $buffer .= $indent . '        title="';
        $value = $context->find('str');
        $buffer .= $this->sectionEf182a623a7e3d255977cee5b4aa1302($context, $indent, $value);
        $buffer .= '"
';
        $buffer .= $indent . '    >
';
        $buffer .= $indent . '        ';
        $value = $context->find('pix');
        $buffer .= $this->section31a49b2ab335cdb4725ddb42ed8de96c($context, $indent, $value);
        $buffer .= '
';
        $buffer .= $indent . '        <span class="visually-hidden">';
        $value = $context->find('str');
        $buffer .= $this->sectionEf182a623a7e3d255977cee5b4aa1302($context, $indent, $value);
        $buffer .= '</span>
';
        $buffer .= $indent . '    </button>
';
        $buffer .= $indent . '</div>
';
        $buffer .= $indent . '
';
        $value = $context->find('js');
        $buffer .= $this->section4c4d3d1165d9d8ebc08eb9481ea759a2($context, $indent, $value);

        return $buffer;
    }

    private function section04b8ae4b53b0a507223620372a841e3e(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
                <input type="hidden" name="{{ name }}" value="{{ value }}">
            ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . '                <input type="hidden" name="';
                $value = $this->resolveValue($context->find('name'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '" value="';
                $value = $this->resolveValue($context->find('value'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '">
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section01a1cb688132d57fc41f0070a1ef998d(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' e/cancel, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' e/cancel, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section256776dc607cdebe9d890b0efd29b5ba(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' closebuttontitle ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' closebuttontitle ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section31a49b2ab335cdb4725ddb42ed8de96c(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' a/search, core ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' a/search, core ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function sectionEf182a623a7e3d255977cee5b4aa1302(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = ' togglesearch ';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= ' togglesearch ';
                $context->pop();
            }
        }
    
        return $buffer;
    }

    private function section4c4d3d1165d9d8ebc08eb9481ea759a2(Mustache_Context $context, $indent, $value)
    {
        $buffer = '';
    
        if (!is_string($value) && is_callable($value)) {
            $source = '
require(
[],
function() {
    const uniqid = "{{uniqid}}";
    const container = document.getElementById(\'searchinput-navbar-\' + uniqid);
    const collapse = container.querySelector(\'#searchform-navbar-\' + uniqid);
    const opensearch = container.querySelector(\'[data-action="opensearch"]\');
    const input = collapse.querySelector(\'[data-region="input"]\');
    const submit = collapse.querySelector(\'[data-action="submit"]\');

    submit.addEventListener(\'click\', (e) => {
        if (input.valUE === \'\') {
            e.preventDefault();
        }
    });

    collapse.addEventListener(\'hidden.bs.collapse\', () => {
        opensearch.classList.remove(\'d-none\');
        input.value = \'\';
    });

    collapse.addEventListener(\'show.bs.collapse\', () => {
        opensearch.classList.add(\'d-none\');
    });

    collapse.addEventListener(\'shown.bs.collapse\', () => {
        input.focus();
    });
});
';
            $result = (string) call_user_func($value, $source, $this->lambdaHelper);
            $buffer .= $result;
        } elseif (!empty($value)) {
            $values = $this->isIterable($value) ? $value : array($value);
            foreach ($values as $value) {
                $context->push($value);
                
                $buffer .= $indent . 'require(
';
                $buffer .= $indent . '[],
';
                $buffer .= $indent . 'function() {
';
                $buffer .= $indent . '    const uniqid = "';
                $value = $this->resolveValue($context->find('uniqid'), $context);
                $buffer .= ($value === null ? '' : call_user_func($this->mustache->getEscape(), $value));
                $buffer .= '";
';
                $buffer .= $indent . '    const container = document.getElementById(\'searchinput-navbar-\' + uniqid);
';
                $buffer .= $indent . '    const collapse = container.querySelector(\'#searchform-navbar-\' + uniqid);
';
                $buffer .= $indent . '    const opensearch = container.querySelector(\'[data-action="opensearch"]\');
';
                $buffer .= $indent . '    const input = collapse.querySelector(\'[data-region="input"]\');
';
                $buffer .= $indent . '    const submit = collapse.querySelector(\'[data-action="submit"]\');
';
                $buffer .= $indent . '
';
                $buffer .= $indent . '    submit.addEventListener(\'click\', (e) => {
';
                $buffer .= $indent . '        if (input.valUE === \'\') {
';
                $buffer .= $indent . '            e.preventDefault();
';
                $buffer .= $indent . '        }
';
                $buffer .= $indent . '    });
';
                $buffer .= $indent . '
';
                $buffer .= $indent . '    collapse.addEventListener(\'hidden.bs.collapse\', () => {
';
                $buffer .= $indent . '        opensearch.classList.remove(\'d-none\');
';
                $buffer .= $indent . '        input.value = \'\';
';
                $buffer .= $indent . '    });
';
                $buffer .= $indent . '
';
                $buffer .= $indent . '    collapse.addEventListener(\'show.bs.collapse\', () => {
';
                $buffer .= $indent . '        opensearch.classList.add(\'d-none\');
';
                $buffer .= $indent . '    });
';
                $buffer .= $indent . '
';
                $buffer .= $indent . '    collapse.addEventListener(\'shown.bs.collapse\', () => {
';
                $buffer .= $indent . '        input.focus();
';
                $buffer .= $indent . '    });
';
                $buffer .= $indent . '});
';
                $context->pop();
            }
        }
    
        return $buffer;
    }

}
