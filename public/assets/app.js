(() => {
  "use strict";

  const form = document.querySelector("#provision-form");
  const lineList = document.querySelector("#line-list");
  const addLineButton = document.querySelector("#add-line");
  const macInput = document.querySelector("#mac");
  const labelInput = document.querySelector("#phone-label");
  const logoInput = document.querySelector("#logo-url");
  const labelCount = document.querySelector("#label-count");
  const lineCount = document.querySelector("#line-count");
  const unprovisionedNote = document.querySelector("#unprovisioned-note");
  const configOutput = document.querySelector("#config-output");
  const previewFilename = document.querySelector("#preview-filename");
  const targetPath = document.querySelector("#target-path");
  const pageMessage = document.querySelector("#page-message");
  const tftpRoot = document.querySelector("main").dataset.tftpRoot;

  function cleanMac(value) {
    return value.toUpperCase().replace(/[^0-9A-F]/g, "").slice(0, 12);
  }

  function formatMac(value) {
    return cleanMac(value).match(/.{1,2}/g)?.join(":") ?? "";
  }

  function safeValue(value) {
    return value.replace(/["\r\n\x00-\x1F\x7F]/g, "");
  }

  function lineCards() {
    return Array.from(lineList.querySelectorAll("[data-line-card]"));
  }

  function lineValue(card, field) {
    return card.querySelector(`[data-field="${field}"]`).value;
  }

  function buildConfig() {
    const cards = lineCards();
    const output = [
      "# Cisco SIP Configuration",
      "",
      `phone_label: "${safeValue(labelInput.value)}"`,
      `logo_url: "${safeValue(logoInput.value)}"`,
    ];

    for (let index = 0; index < 6; index += 1) {
      const card = cards[index];
      const number = index + 1;

      if (!card) {
        output.push(
          `line${number}_name: "UNPROVISIONED"`,
          `line${number}_shortname: "UNPROVISIONED"`,
          `line${number}_displayname: "UNPROVISIONED"`,
          `line${number}_password: "UNPROVISIONED"`,
          `line${number}_authname: "UNPROVISIONED"`,
        );
        continue;
      }

      const extension = safeValue(lineValue(card, "extension"));
      const displayName = safeValue(lineValue(card, "display_name")) || extension;
      const password = safeValue(lineValue(card, "password"));
      const authName = safeValue(lineValue(card, "auth_name")) || extension;

      output.push(
        `line${number}_name: "${extension}"`,
        `line${number}_shortname: "${extension}"`,
        `line${number}_displayname: "${displayName}"`,
        `line${number}_password: "${password}"`,
        `line${number}_authname: "${authName}"`,
      );
    }

    return `${output.join("\n")}\n`;
  }

  function syncPreview() {
    const mac = cleanMac(macInput.value);
    const filename = mac.length === 12 ? `SIP${mac}.cnf` : "SIPMACADDRESS.cnf";

    labelCount.textContent = String(labelInput.value.length);
    previewFilename.textContent = filename;
    targetPath.textContent = `${tftpRoot}/${filename}`;
    configOutput.textContent = buildConfig();
  }

  function reindexLines() {
    const cards = lineCards();

    cards.forEach((card, index) => {
      const number = index + 1;
      card.querySelector("legend").textContent = `Line ${number}`;

      card.querySelectorAll("[data-field]").forEach((input) => {
        input.name = `lines[${index}][${input.dataset.field}]`;
      });

      const removeButton = card.querySelector("[data-remove-line]");
      removeButton.hidden = cards.length === 1;
      removeButton.setAttribute("aria-label", `Remove line ${number}`);

      const extensionInput = card.querySelector('[data-field="extension"]');
      extensionInput.dataset.previous = extensionInput.value;
    });

    lineCount.textContent = String(cards.length);
    addLineButton.disabled = cards.length >= 6;

    if (cards.length < 6) {
      const firstUnused = cards.length + 1;
      unprovisionedNote.textContent = firstUnused === 6
        ? "Line 6 will be set to UNPROVISIONED."
        : `Lines ${firstUnused}–6 will be set to UNPROVISIONED.`;
    } else {
      unprovisionedNote.textContent = "All six lines are configured.";
    }

    syncPreview();
  }

  function createLineCard() {
    const fieldset = document.createElement("fieldset");
    fieldset.className = "line-card";
    fieldset.dataset.lineCard = "";
    fieldset.innerHTML = `
      <legend>Line</legend>
      <button class="remove-button" type="button" data-remove-line>Remove</button>
      <div class="line-grid">
        <label class="field">
          <span>Extension</span>
          <input data-field="extension" inputmode="numeric" placeholder="1234" autocomplete="off" required>
        </label>
        <label class="field">
          <span>Display name <em>optional</em></span>
          <input data-field="display_name" placeholder="Defaults to extension" autocomplete="off">
        </label>
        <label class="field">
          <span>Auth name <em>optional</em></span>
          <input data-field="auth_name" placeholder="Defaults to extension" autocomplete="off">
        </label>
        <label class="field">
          <span>SIP secret</span>
          <input data-field="password" type="text" placeholder="Extension secret" autocomplete="off" spellcheck="false" required>
        </label>
      </div>
    `;
    return fieldset;
  }

  addLineButton.addEventListener("click", () => {
    if (lineCards().length >= 6) return;
    lineList.append(createLineCard());
    pageMessage.textContent = `Line ${lineCards().length} added`;
    reindexLines();
  });

  lineList.addEventListener("click", (event) => {
    const removeButton = event.target.closest("[data-remove-line]");
    if (!removeButton || lineCards().length === 1) return;
    removeButton.closest("[data-line-card]").remove();
    pageMessage.textContent = "Line removed";
    reindexLines();
  });

  form.addEventListener("input", (event) => {
    if (event.target.matches('[data-field="extension"]')) {
      const card = event.target.closest("[data-line-card]");
      const previous = event.target.dataset.previous ?? "";
      const displayName = card.querySelector('[data-field="display_name"]');
      const authName = card.querySelector('[data-field="auth_name"]');

      if (displayName.value === "" || displayName.value === previous) {
        displayName.value = event.target.value;
      }
      if (authName.value === "" || authName.value === previous) {
        authName.value = event.target.value;
      }
      event.target.dataset.previous = event.target.value;
    }

    pageMessage.textContent = "Unsaved changes";
    syncPreview();
  });

  macInput.addEventListener("blur", () => {
    macInput.value = formatMac(macInput.value);
    syncPreview();
  });

  form.addEventListener("submit", () => {
    pageMessage.textContent = "Creating configuration…";
  });

  reindexLines();
})();
