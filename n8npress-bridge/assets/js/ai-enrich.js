(function ($) {
  'use strict';

  var $btn = $('#n8npress-enrich-btn');
  var $result = $('#n8npress-enrich-result');

  if (!$btn.length) return;

  $btn.on('click', function () {
    var productId = $('#post_ID').val();
    if (!productId) return;

    var options = {
      generate_description: $('input[name="n8npress_gen_desc"]').is(':checked'),
      generate_meta_title: $('input[name="n8npress_gen_meta"]').is(':checked'),
      generate_meta_description: $('input[name="n8npress_gen_meta"]').is(':checked'),
      generate_faq: $('input[name="n8npress_gen_faq"]').is(':checked'),
      generate_schema: $('input[name="n8npress_gen_schema"]').is(':checked'),
      generate_alt_text: $('input[name="n8npress_gen_alt"]').is(':checked'),
    };

    $btn.prop('disabled', true).text('AI processing...');
    $result.html('');

    $.ajax({
      url: n8npressAI.rest_url,
      method: 'POST',
      headers: { 'X-WP-Nonce': n8npressAI.nonce },
      contentType: 'application/json',
      data: JSON.stringify({ product_id: parseInt(productId, 10), options: options }),
    })
      .done(function (res) {
        $btn.prop('disabled', false).text('AI Enrich');
        $result.html(
          '<p style="color:#46b450;">' + (res.message || 'Sent to n8n') + '</p>'
        );
      })
      .fail(function (xhr) {
        $btn.prop('disabled', false).text('AI Enrich');
        var msg = xhr.responseJSON ? xhr.responseJSON.message : 'Connection error';
        $result.html('<p style="color:#dc3232;">' + msg + '</p>');
      });
  });
})(jQuery);
