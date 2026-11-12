(function () {
  var btn = document.getElementById("comprestack-test-btn");
  var status = document.getElementById("comprestack-test-status");
  var usage = document.getElementById("comprestack-usage");
  if (!btn || typeof comprestackAdmin === "undefined") return;

  btn.addEventListener("click", function () {
    btn.disabled = true;
    if (status) {
      status.className = "comprestack-status";
      status.textContent = comprestackAdmin.i18n.testing;
    }

    var body = new FormData();
    body.append("action", "comprestack_test_connection");
    body.append("nonce", comprestackAdmin.nonce);

    fetch(comprestackAdmin.ajaxUrl, {
      method: "POST",
      body: body,
      credentials: "same-origin",
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        btn.disabled = false;
        var message =
          data && data.message ? data.message : comprestackAdmin.i18n.failed;
        if (status) {
          status.textContent = message;
          status.className =
            "comprestack-status " + (data && data.ok ? "is-ok" : "is-error");
        }
        if (usage && data && data.ok && data.message) {
          usage.textContent = data.message;
        }
      })
      .catch(function () {
        btn.disabled = false;
        if (status) {
          status.textContent = comprestackAdmin.i18n.failed;
          status.className = "comprestack-status is-error";
        }
      });
  });
})();
