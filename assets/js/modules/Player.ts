export default class Player {
	id: number;
	dom: HTMLDivElement;
	name: string;
	captain: boolean;
	sub: boolean;
	vestSelect: HTMLSelectElement;
    vests: { [index: string]: number }

	constructor(id: number, dom: HTMLDivElement) {
		this.id = id;
		this.dom = dom;
		this.name = dom.innerText;
		this.captain = dom.dataset.captain === '1';
		this.sub = dom.dataset.sub === '1';
        const vests: { [index: string]: number } | [] = JSON.parse(dom.dataset.vests);
        if (vests instanceof Array) {
            this.vests = {};
        } else {
            this.vests = vests;
        }
		this.vestSelect = dom.querySelector('.vest-select') as HTMLSelectElement;
	}

	setVestOptions(options: string[]): void {
		options.sort((a, b) => {
			return parseInt(a) - parseInt(b);
		});
		const prevValue = this.vestSelect.value;
		this.vestSelect.querySelectorAll('option').forEach(option => {
			if (option.value !== '') {
				option.remove();
			}
		});

		options.forEach(vest => {
			const option = document.createElement('option');
			option.value = vest;
			option.innerText = vest;
			this.vestSelect.appendChild(option);
		});

		this.vestSelect.value = prevValue;
	}

}