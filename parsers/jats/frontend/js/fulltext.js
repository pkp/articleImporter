new function () {
	const defaultLanguage = "en";
	const language = top.document.documentElement.lang?.split("-").shift().toLowerCase() ?? defaultLanguage;
	const translations = {
		"Back to Text": {
			"es": "Volver al Texto",
			"pt": "Voltar ao Texto"
		},
		"Date Received": {
			"es": "Fecha de Recepción",
			"pt": "Data de  Recebimento"
		},
		"Date Accepted": {
			"es": "Fecha de Aceptación",
			"pt": "Data de Aceitação"
		},
		"Date Modified": {
			"es": "Fecha de Modificación",
			"pt": "Data de Modificação"
		},
		"Date": {
			"es": "Fecha",
			"pt": "Data"
		},
		"Publication Date": {
			"es": "Fecha de Publicación",
			"pt": "Data de Publicação"
		},
		"Abstract": {
			"es": "Resumen",
			"pt": "Resumo"
		},
		"Affiliation": {
			"es": "Afiliación",
			"pt": "Afiliação"
		},
		"Notes": {
			"es": "Notas",
			"pt": "Notas"
		},
		"Author Notes": {
			"es": "Notas del Autor",
			"pt": "Notas do Autor"
		},
		"Correspondence To": {
			"es": "Correspondencia a",
			"pt": "Correspondência Para"
		}
	};

	new function translate() {
		if (language === defaultLanguage) {
			return;
		}
		for (const tag of document.querySelectorAll(".translate, .generated")) {
			let content = tag.textContent.trim();
			if (~content.indexOf("Publication Date")) {
				content = "Publication Date";
			}
			const translation = translations[content]?.[language];
			if (translation) {
				tag.textContent = tag.textContent.replace(content, translation);
			}
		}
	};

	new function backLink() {
		let backTo = null;
		const backLink = document.createElement("a");
		backLink.innerHTML = `<strong>📑 ${translations["Back to Text"][language] ?? "Back to Text"}</strong>`;
		backLink.href = "#";
		backLink.className = "back-link";
		backLink.addEventListener("click", function (event) {
			event.preventDefault();
			if (backTo) {
				backTo.scrollIntoView();
				backLink.parentNode.removeChild(backLink);
			}
		});
		this.handleEvent = function (event) {
			event.preventDefault();
			const id = event.currentTarget.hash.slice(1);
			const target = document.body.querySelector(`[id=${id}],[name=${id}]`);
			if (target) {
				backTo = event.currentTarget;
				target.parentElement.insertBefore(backLink, target);
				target.scrollIntoView();
				scrollBy({top: -backLink.offsetHeight});
			}
		};
		for (const tag of document.body.querySelectorAll("a")) {
			if (tag.hash.length > 1) {
				tag.addEventListener("click", this);
			}
		}
	};

	new function lightBox() {
		for (const img of document.querySelectorAll("a[href] > img")) {
				img.parentNode.dataset.lightbox = "lightbox";
		}
		const loadScript = src => new Promise((resolve, reject) => {
				if (document.querySelector(`head > script[src="${src}"]`) !== null) {
						return resolve();
				}
				const script = Object.assign(document.createElement("script"), {
						src,
						async: true,
						onload: resolve,
						onerror: reject
				});
				document.head.appendChild(script);
		});
		const link = Object.assign(document.createElement('link'), {
				rel: 'stylesheet',
				type: 'text/css',
				href: '/styles/lightbox.min.css'
		});
		document.head.appendChild(link);
		loadScript("/js/lightbox-plus-jquery.min.js").then(() => {
				lightbox.option({
						resizeDuration: 200,
						wrapAround: true,
						fitImagesInViewport: false
				});
		});
	};
	if (parent.document !== document) {
		parent.document.body.style.overflow = "hidden";
	}
};
