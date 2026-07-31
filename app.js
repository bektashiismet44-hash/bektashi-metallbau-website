const form = document.querySelector("#contact-form");
const statusBox = document.querySelector("#form-status");
const submitButton = form?.querySelector('button[type="submit"]');
const startedField = form?.elements.namedItem("form_started");
const year = document.querySelector("#current-year");

if (year) year.textContent = String(new Date().getFullYear());

function resetStartTime() {
  if (startedField instanceof HTMLInputElement) startedField.value = String(Date.now());
}

resetStartTime();

form?.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!(form instanceof HTMLFormElement) || !(submitButton instanceof HTMLButtonElement)) return;

  submitButton.disabled = true;
  submitButton.textContent = "Wird gesendet …";
  statusBox.textContent = "";
  statusBox.className = "form-status";

  try {
    const response = await fetch(form.action, {
      method: "POST",
      body: new FormData(form),
      headers: { Accept: "application/json" },
    });
    const result = await response.json().catch(() => null);
    if (!response.ok || !result?.ok) throw new Error(result?.message || "send_failed");

    form.reset();
    resetStartTime();
    statusBox.textContent = "Vielen Dank. Ihre Anfrage wurde direkt an Bektashi Metallbau gesendet.";
    statusBox.className = "form-status success";
  } catch {
    statusBox.innerHTML = 'Die Nachricht konnte nicht gesendet werden. Schreiben Sie bitte an <a href="mailto:i.b@bektashi-metallbau.ch">i.b@bektashi-metallbau.ch</a>.';
    statusBox.className = "form-status error";
  } finally {
    submitButton.disabled = false;
    submitButton.innerHTML = "Anfrage senden <span>→</span>";
  }
});
