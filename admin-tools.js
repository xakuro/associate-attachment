var AssociateAttachmentTool;

(function($) {
  AssociateAttachmentTool = function(parameters) {
    let
      self = this,
      total_count = parameters.post_ids.length,
      counter = 0,
      successes_counter = 0,
      error_counter = 0,
      abort_flag = false;

    this.sprintf = function() {
      let arg = $.makeArray(arguments), template = arg.shift(), i;
      for (i in arg) {
          template = template.replace("%s", arg[i]);
      }
      return template;
    };

    this.updateStatus = function(response) {
      counter += response.count;
      successes_counter += (response.count - response.error_count);
      error_counter += response.error_count;

      $("#associate-attachment-bar").progressbar("value", (counter / total_count) * 100);
      $("#associate-attachment-bar-percent").html(Math.round((counter / total_count) * 100) + "%");
      $("#associate-attachment-success-count").html(successes_counter);
      $("#associate-attachment-error-count").html(error_counter);
      for (let message of response.messages) {
        $("#associate-attachment-msg").append("<li>" + message + "</li>");
      }
    };

    this.finalization = function() {
      let $message = $("#message");
      let s;

      $("#associate-attachment-stop-bottun").hide();
      $("#associate-attachment-message").hide();
      $("#associate-attachment-back-link").show();

      if ( abort_flag ) {
        s = parameters.about_message;
        $message.addClass("notice-success");
      } else {
        if (0 === parameters.post_ids.length) {
          if (0 === error_counter) {
            s = parameters.success_message;
          } else {
            s = this.sprintf(parameters.failure_message, error_counter);
          }
          $message.addClass("notice-success");
        } else {
          s = parameters.error_message;
          $message.addClass("notice-warning");
        }
      }
      $message.html("<p><strong>" + s + "</strong></p>");
      $message.show();
    };

    this.init = function(id) {
      $("#associate-attachment-bar").progressbar();
      $("#associate-attachment-bar-percent").html("0%");

      $("#associate-attachment-stop-bottun").on("click", function(btn) {
        abort_flag = true;
        $('#associate-attachment-stop-bottun').prop("disabled", true);
        $('#associate-attachment-stop-bottun').val(parameters.stop_button_message);
      });
    };

    this.start = function(ids) {
      $.ajax({
        type: "POST",
        url: ajaxurl,
        data: { action: "associate_attachment", nonce: parameters.nonce, ids: ids, enable_acf: parameters.enable_acf, enable_scf: parameters.enable_scf, enable_shortcode: parameters.enable_shortcode }
      }).done(function(response) {
        if (response !== Object(response)) {
          response = new Object;
          response.count = 0;
        }
        self.updateStatus(response);
        if (parameters.post_ids.length && !abort_flag && response.count === ids.length) {
          self.start(parameters.post_ids.splice(0, parameters.count_per_step));
        } else {
          self.finalization();
        }
      });
    };
    
    this.init();
    this.start(parameters.post_ids.splice(0, parameters.count_per_step));
  };
} (jQuery));
