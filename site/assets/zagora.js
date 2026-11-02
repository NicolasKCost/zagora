// Zágora — interações da landing. Tudo aqui é "enfeite": sem JS a página continua completa e legível.
(function () {
  var reduzir = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // País pelo fuso horário do aparelho (onde a pessoa ESTÁ — o idioma não diz isso). Quem está no Brasil entra no bot
  // pelo link do Brasil (real + preços BR); quem está em Portugal, pelo de Portugal — em qualquer das duas páginas.
  // Outros países (ou navegador sem fuso) ficam com o link da própria página.
  var fuso = '';
  try { fuso = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
  var pais = /^America\/(Sao_Paulo|Araguaina|Bahia|Belem|Boa_Vista|Campo_Grande|Cuiaba|Eirunepe|Fortaleza|Maceio|Manaus|Noronha|Porto_Velho|Recife|Rio_Branco|Santarem)$|^Brazil\//.test(fuso) ? 'br'
    : /^(Europe\/Lisbon|Atlantic\/(Madeira|Azores)|Portugal)$/.test(fuso) ? 'pt' : null;
  if (pais) {
    document.querySelectorAll('a[href*="t.me/ozagora_bot?start=site_"]').forEach(function (a) {
      a.href = a.href.replace(/start=site_(br|pt)\b/, 'start=site_' + pais);
    });
    // Está na página do outro país (preços em outra moeda): oferece a página certa, sem redirecionar sozinho
    var paginaPt = /^\/pt(\/|$)/.test(location.pathname);
    var fechou = false;
    try { fechou = sessionStorage.getItem('zagora-aviso-pais') === '1'; } catch (e) {}
    if (!fechou && ((pais === 'br' && paginaPt) || (pais === 'pt' && !paginaPt && location.pathname === '/'))) {
      var aviso = document.createElement('div');
      aviso.className = 'aviso-pais';
      var link = document.createElement('a');
      link.href = pais === 'br' ? '/' : '/pt/';
      link.textContent = pais === 'br' ? 'Está no Brasil? Ver a página com preços em real →' : 'Está em Portugal? Ver a página com preços em euros →';
      var fechar = document.createElement('button');
      fechar.type = 'button';
      fechar.setAttribute('aria-label', 'Fechar aviso');
      fechar.textContent = '×';
      fechar.addEventListener('click', function () {
        aviso.remove();
        try { sessionStorage.setItem('zagora-aviso-pais', '1'); } catch (e) {}
      });
      aviso.appendChild(link);
      aviso.appendChild(fechar);
      document.body.insertBefore(aviso, document.body.firstChild);
    }
  }

  // Topo ganha borda/sombra depois de rolar
  var topo = document.querySelector('.topo');
  var ctaCelular = document.querySelector('.cta-celular');
  var hero = document.querySelector('.hero');
  function aoRolar() {
    if (topo) topo.classList.toggle('rolou', window.scrollY > 8);
    if (ctaCelular && hero) ctaCelular.classList.toggle('mostrar', hero.getBoundingClientRect().bottom < 0);
  }
  window.addEventListener('scroll', aoRolar, { passive: true });
  aoRolar();

  // Aparecer ao rolar + barras que enchem
  var observar = 'IntersectionObserver' in window && !reduzir;
  var alvos = document.querySelectorAll('.revelar, .barra');
  function mostrar(el) {
    el.classList.add('visivel');
    var i = el.querySelector && el.querySelector('i[data-largura]');
    if (el.matches('.barra') && i) i.style.width = i.getAttribute('data-largura');
  }
  if (observar) {
    var io = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (e) {
        if (e.isIntersecting) { mostrar(e.target); io.unobserve(e.target); }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    alvos.forEach(function (el) { io.observe(el); });
  } else {
    alvos.forEach(mostrar);
  }

  // Preços: mensal x anual
  var botoesPeriodo = document.querySelectorAll('[data-periodo]');
  botoesPeriodo.forEach(function (b) {
    b.addEventListener('click', function () {
      var periodo = b.getAttribute('data-periodo');
      botoesPeriodo.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      document.querySelectorAll('[data-mensal]').forEach(function (el) {
        el.innerHTML = el.getAttribute(periodo === 'anual' ? 'data-anual' : 'data-mensal');
      });
    });
  });

  // Conversa animada no telefone: roda as "cenas" em loop
  var conversa = document.querySelector('.conversa');
  var dados = document.getElementById('cenas');
  if (!conversa || !dados || reduzir) return;
  var cenas;
  try { cenas = JSON.parse(dados.textContent); } catch (e) { return; }
  if (!cenas.length) return;

  function balao(msg) {
    var el = document.createElement('div');
    el.className = 'balao ' + msg.de;
    el.textContent = msg.texto;
    if (msg.botoes) {
      var linha = document.createElement('div');
      linha.className = 'botoes-bot';
      msg.botoes.forEach(function (t) {
        var s = document.createElement('span');
        s.textContent = t;
        linha.appendChild(s);
      });
      el.appendChild(linha);
    }
    return el;
  }

  function limitar() {
    // Mantém só o que cabe na tela (tira as mensagens mais antigas)
    while (conversa.scrollHeight > conversa.clientHeight + 4 && conversa.children.length > 1) {
      conversa.removeChild(conversa.firstChild);
    }
  }

  var visivel = true;
  document.addEventListener('visibilitychange', function () { visivel = !document.hidden; });

  function esperar(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  async function rodar() {
    var n = 0;
    await esperar(2200); // deixa a primeira cena (que já vem no HTML) ser lida
    while (true) {
      var cena = cenas[++n % cenas.length];
      if (!visivel) { await esperar(1000); continue; }
      // Não apaga a conversa: as mensagens novas empurram as antigas pra cima, como num chat de verdade
      for (var i = 0; i < cena.length; i++) {
        var msg = cena[i];
        if (msg.de === 'bot') {
          var dig = document.createElement('div');
          dig.className = 'digitando';
          dig.innerHTML = '<i></i><i></i><i></i>';
          conversa.appendChild(dig);
          limitar();
          await esperar(900);
          conversa.removeChild(dig);
        }
        conversa.appendChild(balao(msg));
        limitar();
        await esperar(msg.de === 'eu' ? 700 : 1800);
      }
      await esperar(2600);
    }
  }
  rodar();
})();
