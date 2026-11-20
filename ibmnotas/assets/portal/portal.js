import {bindThemeButtons} from './theme.js';

bindThemeButtons();

const menu = document.querySelector('.mobile-menu');
const sidebar = document.querySelector('.sidebar');
menu?.addEventListener('click', () => {
  menu.setAttribute('aria-expanded', String(sidebar.classList.toggle('open')));
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && sidebar?.classList.contains('open')) {
    sidebar.classList.remove('open');
    menu.setAttribute('aria-expanded', 'false');
    menu.focus();
  }
});
const toggle = document.querySelector('#toggle-password');
toggle?.addEventListener('click', () => {
  const password = document.querySelector('#login-password');
  const show = password.type === 'password';
  password.type = show ? 'text' : 'password';
  toggle.textContent = show ? 'Ocultar' : 'Ver';
  toggle.setAttribute('aria-pressed', String(show));
  toggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
});
const help = document.querySelector('#login-help');
help?.addEventListener('click', () => document.querySelector('#access-help').showModal());
