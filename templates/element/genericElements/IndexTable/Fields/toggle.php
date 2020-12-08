<?php
/*
 *  Toggle element - a simple checkbox with the current state selected
 *  On click, issues a GET to a given endpoint, retrieving a form with the
 *  value flipped, which is immediately POSTed.
 *  to fetch it.
 *
 */
    $data = $this->Hash->extract($row, $field['data_path']);
    $seed = rand();
    $checkboxId = 'GenericToggle-' . $seed;
    $tempboxId = 'TempBox-' . $seed;

    $requirementMet = true;
    if (isset($field['toggle_requirement'])) {
        if (isset($field['toggle_requirement']['options']['datapath'])) {
            foreach ($field['toggle_requirement']['options']['datapath'] as $name => $path) {
                $field['toggle_requirement']['options']['datapath'][$name] = empty($this->Hash->extract($row, $path)[0]) ? null : $this->Hash->extract($row, $path)[0];
            }
        }
        $options = isset($field['toggle_requirement']['options']) ? $field['toggle_requirement']['options'] : array();
        $requirementMet = $field['toggle_requirement']['function']($row, $options);
    }

    echo sprintf(
        '<input type="checkbox" id="%s" %s %s><span id="%s" class="d-none">',
        $checkboxId,
        empty($data[0]) ? '' : 'checked',
        $requirementMet ? '' : 'disabled="disabled"',
        $tempboxId
    );
?>

<?php if ($requirementMet): ?>
<script type="text/javascript">
$(document).ready(function() {
    var url = "<?= h($field['url']) ?>";
    <?php
        if (!empty($field['url_params_data_paths'][0])) {
            $id = $this->Hash->extract($row, $field['url_params_data_paths'][0]);
            echo 'url = url +  "/' . h($id[0]) . '";';
        }
    ?>
    $('#<?= $checkboxId ?>').click(function(evt) {
        evt.preventDefault();
        $.ajax({
            type:"get",
            url: url,
            error:function() {
                showToast({
                    variant: 'danger',
                    title: '<?= __('Could not retrieve current state.') ?>.'
                })
            },
            success: function (data, textStatus) {
                $('#<?= $tempboxId ?>').html(data);
                var $form = $('#<?= $tempboxId ?>').find('form');
                $.ajax({
                    data: $form.serialize(),
                    cache: false,
                    type:"post",
                    url: $form.attr('action'),
                    success:function(data, textStatus) {
                        showToast({
                            variant: 'success',
                            title: data.message
                        })
                        if (data.success) {
                            $('#<?= $checkboxId ?>').prop('checked', !$('#<?= $checkboxId ?>').prop('checked'));
                        }
                    },
                    error:function() {
                        showToast({
                            variant: 'danger',
                            title: data.message
                        })
                    },
                    complete:function() {
                        $('#<?= $tempboxId ?>').empty();
                    }
                });
            }
        });
    });
});
</script>
<?php endif; ?>