// Tab elements
const loginTab = document.getElementById("loginTab");
const registerTab = document.getElementById("registerTab");
const verifyTab = document.getElementById("verifyTab");
const loginForm = document.getElementById("loginForm");
const registerForm = document.getElementById("registerForm");
const verifyForm = document.getElementById("verifyForm");

// Tab switching logic
function activateTab(tab, form) {
  // Remove active and hidden classes from all tabs and forms
  [loginTab, registerTab, verifyTab].forEach(t => {
    if (t) {
      t.classList.remove("active");
      t.classList.remove("hidden");
    }
  });
  [loginForm, registerForm, verifyForm].forEach(f => {
    if (f) f.classList.remove("active");
  });

  // Add active class to the selected tab and form
  if (tab) tab.classList.add("active");
  if (form) form.classList.add("active");

  // Hide verifyTab unless explicitly activated
  if (verifyTab && tab !== verifyTab) {
    verifyTab.classList.add("hidden");
  }
}

// Attach tab event listeners
if (loginTab && loginForm) {
  loginTab.addEventListener("click", () => {
    activateTab(loginTab, loginForm);
  });
}

if (registerTab && registerForm) {
  registerTab.addEventListener("click", () => {
    activateTab(registerTab, registerForm);
  });
}

// Password validation for register form
const passwordInput = registerForm?.querySelector('input[name="password"]');
const confirmInput = registerForm?.querySelector('input[name="password_confirmation"]');
const passwordError = document.createElement('div');
passwordError.classList.add('error-message');
passwordError.style.color = 'red';
passwordError.style.fontSize = '12px';
passwordError.style.marginTop = '4px';
if (passwordInput) {
  passwordInput.parentNode.insertBefore(passwordError, passwordInput.nextSibling);
}

const confirmError = document.createElement('div');
confirmError.classList.add('error-message');
confirmError.style.color = 'red';
confirmError.style.fontSize = '12px';
confirmError.style.marginTop = '4px';
if (confirmInput) {
  confirmInput.parentNode.insertBefore(confirmError, confirmInput.nextSibling);
}

function validatePassword() {
  const password = passwordInput?.value || '';
  let error = '';
  if (password.length < 8) {
    error = 'The password must be at least 8 characters.';
  }
  passwordError.textContent = error;
  if (passwordInput) {
    passwordInput.style.borderColor = error ? 'red' : '';
  }
}

function validateConfirm() {
  const password = passwordInput?.value || '';
  const confirm = confirmInput?.value || '';
  let error = '';
  if (password !== confirm) {
    error = 'Passwords do not match.';
  }
  confirmError.textContent = error;
  if (confirmInput) {
    confirmInput.style.borderColor = error ? 'red' : '';
  }
}

if (passwordInput) {
  passwordInput.addEventListener('input', validatePassword);
}

if (confirmInput) {
  confirmInput.addEventListener('input', () => {
    validatePassword();
    validateConfirm();
  });
}

// Prevent submit if password errors
if (registerForm) {
  registerForm.addEventListener('submit', (e) => {
    validatePassword();
    validateConfirm();
    if (passwordError.textContent || confirmError.textContent) {
      e.preventDefault();
    }
  });
}

// Verification code input handling
if (verifyForm) {
  const inputs = verifyForm.querySelectorAll(".code-input");
  const hidden = document.getElementById("fullCode");
  inputs.forEach((input, idx) => {
    input.addEventListener("input", () => {
      input.value = input.value.replace(/\D/g, "").slice(0, 1);
      if (input.value && idx < inputs.length - 1) inputs[idx + 1].focus();
      hidden.value = Array.from(inputs).map(i => i.value).join("");
    });
    input.addEventListener("keydown", (e) => {
      if (e.key === "Backspace" && !input.value && idx > 0) {
        inputs[idx - 1].focus();
      }
    });
  });
}

// Resend Code Timer
// Resend Code Timer
const resendButton = document.getElementById('resendCodeBtn');
if (resendButton) {
  const timerKey = 'resendCooldown';
  let timeLeft = 0;
  const savedTimestamp = localStorage.getItem(timerKey);

  if (savedTimestamp) {
    const elapsed = Math.floor((Date.now() - parseInt(savedTimestamp)) / 1000);
    timeLeft = Math.max(0, 120 - elapsed);
  } else if (window.isVerifyActive) {
    timeLeft = 120;
    localStorage.setItem(timerKey, Date.now());
  }

  function runTimer() {
    if (timeLeft > 0) {
      resendButton.disabled = true;
      resendButton.textContent = `Resend Code (${timeLeft}s)`;
      const timer = setInterval(() => {
        timeLeft -= 1;
        resendButton.textContent = `Resend Code (${timeLeft}s)`;
        if (timeLeft <= 0) {
          clearInterval(timer);
          resendButton.disabled = false;
          resendButton.textContent = 'Resend Code';
          localStorage.removeItem(timerKey);
        }
      }, 1000);
    }
  }

  runTimer();

  resendButton.addEventListener('click', (e) => {
    if (resendButton.disabled) {
      e.preventDefault();
      return;
    }
    timeLeft = 120;
    localStorage.setItem(timerKey, Date.now());
    runTimer();
  });
}


// Theme toggle
const themeToggle = document.getElementById("themeToggle");
if (themeToggle) {
  themeToggle.addEventListener("click", () => {
    document.body.classList.toggle("dark");
    if (document.body.classList.contains("dark")) {
      localStorage.setItem("theme", "dark");
    } else {
      localStorage.setItem("theme", "light");
    }
  });
}

window.addEventListener("DOMContentLoaded", () => {
  const savedTheme = localStorage.getItem("theme");
  if (!savedTheme || savedTheme === "dark") {
    document.body.classList.add("dark");
  } else {
    document.body.classList.remove("dark");
  }

  // Ensure verifyTab is hidden unless explicitly needed
  if (verifyTab && !verifyForm?.classList.contains("active")) {
    verifyTab.classList.add("hidden");
  }
});

// Toastr options
toastr.options = {
  "closeButton": true,
  "progressBar": true,
  "newestOnTop": true,
  "preventDuplicates": true,
  "timeOut": 4000,
  "positionClass": "toast-top-center"
};