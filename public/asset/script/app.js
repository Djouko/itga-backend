var user_type = $("#user_type").val();
var toastPosition = "bottomCenter";

function showAdminToast(title, message, color, icon) {
  if (typeof iziToast === "undefined") {
    return;
  }

  iziToast.show({
    title,
    message,
    color,
    position: toastPosition,
    transitionIn: "fadeInUp",
    transitionOut: "fadeOutDown",
    timeout: 3000,
    animateInside: false,
    iconUrl: `${domainUrl}asset/img/${icon}`,
  });
}

function adminAjaxErrorMessage(xhr) {
  if (!xhr) {
    return "Request failed. Please try again.";
  }

  if (xhr.responseJSON && xhr.responseJSON.message) {
    return xhr.responseJSON.message;
  }

  if (xhr.status === 401 || xhr.status === 419) {
    return "Your admin session expired. Please sign in again.";
  }

  if (xhr.status === 403) {
    return "You do not have permission to perform this action.";
  }

  if (xhr.status === 413) {
    return "Uploaded file is too large. Please choose a smaller file.";
  }

  if (xhr.status === 429) {
    const retryAfter = xhr.getResponseHeader("Retry-After");
    return retryAfter
      ? `Too many requests. Please retry in ${retryAfter} second(s).`
      : "Too many requests. Please retry later.";
  }

  if (xhr.status >= 500) {
    return "Server error. Please try again or contact support.";
  }

  return "Request failed. Please try again.";
}

function resetAdminSubmitButtons() {
  $(".saveButton, .saveButton1, .saveButton2, .saveButton3, .addFakeData")
    .removeClass("spinning disabled")
    .prop("disabled", false);
}

$.ajaxSetup({
  headers: {
    "X-CSRF-TOKEN": $('meta[name="csrf-token"]').attr("content"),
  },
  timeout: 30000,
});

$(document).ajaxError(function (_event, xhr, settings) {
  if (settings && settings.suppressGlobalErrorToast) {
    return;
  }

  showAdminToast("Request failed", adminAjaxErrorMessage(xhr), "red", "x.svg");
});

$(document).ajaxComplete(function () {
  resetAdminSubmitButtons();
});

$(document).on("hidden.bs.modal", function () {
  if ($("form")[0]) {
    $("form")[0].reset();
  }
  $(this).data("bs.modal", null);
  $(".swiper-slide video").attr("src", "");
  $("video").attr("src", "");
  // $("audio").attr("src", "");
  $("#comment-content").html("");
  $("#comment-content1").html("");
  $("#comment-content2").html("");
  $("#no_comments").hide();
  $("#no_comments1").hide();
  $("#no_comments2").hide();
});

$(document).on("hidden.bs.modal", function () {
  $("form").trigger("reset");
  $(this).data("bs.modal", null);

  $(".saveButton").removeClass("spinning disabled");

  $(".saveButton1").removeClass("spinning disabled");
});

$("form").on("submit", function () {
  $(".saveButton, .saveButton1").addClass("spinning disabled").prop("disabled", true);
});

$("#admobAndroidForm").on("submit", function () {
  $(".saveButton2").addClass("spinning disabled").prop("disabled", true);

  setTimeout(function () {
    $(".saveButton2").removeClass("spinning disabled").prop("disabled", false);
  }, 1000);
});

$("#admobiOSForm").on("submit", function () {
  $(".saveButton3").addClass("spinning disabled").prop("disabled", true);

  setTimeout(function () {
    $(".saveButton3").removeClass("spinning disabled").prop("disabled", false);
  }, 1000);
});

function resetForm(formId) {
  $(formId).trigger("reset");
}
