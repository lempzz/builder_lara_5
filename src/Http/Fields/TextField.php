<?php

namespace Vis\Builder\Fields;

use Illuminate\Database\Eloquent\Builder;

class TextField extends AbstractField
{
    public function isEditable()
    {
        return true;
    }

    public function onSearchFilter(Builder $db, $value)
    {
        $tabs = $this->getAttribute('tabs');
        $table = $this->getAttribute('extends_table') ?: $this->definition['db']['table'];

        if ($tabs) {
            $field = $table.'.'.$this->getFieldName();
            $db->where(function (Builder $query) use ($field, $value, $tabs) {
                foreach ($tabs as $tab) {
                    $query->orWhere($field.$tab['postfix'], 'LIKE', '%'.$value.'%');
                }
            });

            return;
        }

        if ($this->getAttribute('filter') == 'integer') {
            $db->where($table.'.'.$this->getFieldName(), $value);

            return;
        }

        $db->where($table.'.'.$this->getFieldName(), 'LIKE', '%'.$value.'%');
    }

    public function getEditInput($row = [])
    {
        if ($this->hasCustomHandlerMethod('onGetEditInput')) {
            $res = $this->handler->onGetEditInput($this, $row);
            if ($res) {
                return $res;
            }
        }

        $type = $this->getAttribute('type');
        $input = view('admin::tb.input_'.$type);
        $input->value = $this->getValue($row);
        $input->name = $this->getFieldName();
        $input->rows = $this->getAttribute('rows');
        $input->mask = $this->getAttribute('mask');
        $input->custom_type = $this->getAttribute('custom_type');
        $input->disabled = $this->getAttribute('disabled');

        if ($input->name == request('foreign_field')) {
            $input->value = request('foreign_field_id');
        }

        $input->placeholder = $this->getAttribute('placeholder');
        $input->is_password = $this->getAttribute('is_password');
        $input->comment = $this->getAttribute('comment');
        $input->only_numeric = $this->getAttribute('only_numeric');
        $input->readonly_for_edit = $this->getAttribute('readonly_for_edit');
        $input->transliteration = $this->getAttribute('transliteration');

        return $input->render();
    }

    public function getListValue($row)
    {
        if ($this->hasCustomHandlerMethod('onGetListValue')) {
            $res = $this->handler->onGetListValue($this, $row);

            if ($res) {
                return $res;
            }
        }

        if ($this->getAttribute('fast_edit')) {
            return  '<p>'.parent::getListValue($row).'</p>';
        }

        return $this->getValue($row);
    }

    public function mask(string $value)
    {
        $this->attributes['mask'] = $value;

        return $this;
    }

    public function customType(string $type)
    {
        $this->attributes['custom_type'] = $type;

        return $this;
    }

    public function onlyNumeric(bool $is = true)
    {
        $this->attributes['only_numeric'] = $is;

        return $this;
    }

    public function readonlyForEdit(bool $is = true)
    {
        $this->attributes['readonly_for_edit'] = $is;

        return $this;
    }

    public function disabled(bool $is = true)
    {
        $this->attributes['disabled'] = $is;

        return $this;
    }
}
